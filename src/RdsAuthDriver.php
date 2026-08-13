<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Psr\Cache\CacheItemPoolInterface;

final class RdsAuthDriver extends AbstractDriverMiddleware
{
    /**
     * The token has a 15-minute lifetime. The cache TTL is shorter to leave a margin
     * for clock skew and for the connection that uses the token. A rotation of the
     * signing credentials can invalidate the token earlier; connectWithIamToken()
     * retries that case.
     *
     * @see https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html
     */
    private const int TOKEN_CACHE_TTL_SECONDS = 600;

    /**
     * RDS rotates the managed master password in Secrets Manager, but the application
     * receives its copy through deployment configuration. Between the rotation and the
     * next deployment the configured password is invalid, so the driver reads the
     * current password from Secrets Manager and caches it for this period to limit the
     * read requests.
     */
    private const int PASSWORD_CACHE_TTL_SECONDS = 600;

    /**
     * A cache is recommended. Without one, every request or CLI invocation signs a new
     * token, and during a rotation every connection makes one rejected attempt and one
     * Secrets Manager read.
     */
    public function __construct(
        Driver $wrapped,
        private readonly RdsIamTokenProvider $tokens,
        private readonly RdsSecretPasswordProvider $passwords,
        private readonly ?string $iamUsername,
        private readonly ?string $secretArn,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly DatabaseEngine $engine = DatabaseEngine::Postgres,
    ) {
        parent::__construct($wrapped);
    }

    /**
     * #[\SensitiveParameter] is not inherited, so every override must repeat it to keep
     * $params['password'] out of stack traces. IAM authentication takes precedence over
     * the secret path.
     *
     * @see https://www.php.net/manual/en/class.sensitiveparameter.php
     * @see https://www.php.net/manual/en/ini.core.php#ini.zend.exception-ignore-args
     *
     * @param array<string, mixed> $params
     *
     * @throws DriverException
     */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        if (null !== $this->iamUsername && '' !== $this->iamUsername) {
            return $this->connectWithIamToken($params, $this->iamUsername);
        }

        if (null !== $this->secretArn && '' !== $this->secretArn) {
            return $this->connectWithManagedPassword($params, $this->secretArn);
        }

        return parent::connect($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws DriverException
     */
    private function connectWithIamToken(
        #[\SensitiveParameter]
        array $params,
        string $iamUser,
    ): Connection {
        $host = (string) ($params['host'] ?? '');
        $port = (int) ($params['port'] ?? $this->engine->defaultPort());
        $base = [...$params, 'user' => $iamUser];

        // MySQL has no sslmode parameter; its TLS comes from driverOptions, and RDS
        // refuses IAM logins without TLS, so a missing configuration fails closed.
        if (DatabaseEngine::Postgres === $this->engine) {
            $base['sslmode'] = $params['sslmode'] ?? 'require';
        }

        $key = $this->tokenKey($host, $port, $iamUser);

        $cached = $this->cachedString($key);
        if (null !== $cached) {
            try {
                return parent::connect([...$base, 'password' => $cached]);
            } catch (DriverException) {
                // A cached token can be stale after a credential rotation, so delete it and
                // continue. A failure not caused by the token recurs on the second attempt and
                // propagates from there.
                $this->forget($key);
            }
        }

        // A token signed in this request cannot be stale, so the call is not retried:
        // a second attempt would only add load to a database that already failed.
        $fresh = $this->tokens->freshToken($host, $port, $iamUser);
        $connection = parent::connect([...$base, 'password' => $fresh]);

        // Cache the token only after the database accepts it, so an outage causes one
        // connection attempt per request.
        $this->remember($key, $fresh, self::TOKEN_CACHE_TTL_SECONDS);

        return $connection;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws DriverException
     */
    private function connectWithManagedPassword(
        #[\SensitiveParameter]
        array $params,
        string $secretArn,
    ): Connection {
        $key = $this->passwordKey($secretArn);

        // Prefer the cached password: after a rotation the configured password is
        // invalid until a restart reloads it. The secret is read only after a rejection.
        $cached = $this->cachedString($key);

        try {
            return parent::connect([...$params, 'password' => $cached ?? $params['password'] ?? '']);
        } catch (DriverException $driverException) {
            // Retry only after an authentication failure; any other failure does not
            // depend on the password.
            if (!$this->isPasswordFailure($driverException)) {
                throw $driverException;
            }

            if (null !== $cached) {
                $this->forget($key);
            }
        }

        // A password read directly from the secret cannot be stale, so the call is not
        // retried; when both attempts fail, the second exception propagates.
        $fresh = $this->passwords->freshPassword($secretArn);
        $connection = parent::connect([...$params, 'password' => $fresh]);

        // Cache the password only after the database accepts it.
        $this->remember($key, $fresh, self::PASSWORD_CACHE_TTL_SECONDS);

        return $connection;
    }

    /**
     * pdo_pgsql reports a rejected password as the generic SQLSTATE 08006 with the
     * cause only in the message text, so the message match detects that case. 28P01
     * and 28000 match drivers that report the specific state. MySQL reports error
     * 1045 under the generic SQLSTATE HY000; the message match requires the
     * "using password" suffix because error 1044 (no privilege on the database)
     * also starts with "Access denied for user" but is not a password failure.
     *
     * @see https://www.postgresql.org/docs/current/errcodes-appendix.html
     * @see https://dev.mysql.com/doc/mysql-errors/8.4/en/server-error-reference.html
     */
    private function isPasswordFailure(DriverException $exception): bool
    {
        if (in_array($exception->getSQLState(), ['28P01', '28000'], true)) {
            return true;
        }

        if (1045 === $exception->getCode()) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'password authentication failed')
            || (str_contains($message, 'Access denied for user') && str_contains($message, 'using password'));
    }

    private function cachedString(string $key): ?string
    {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            return null;
        }

        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    /**
     * Call only with a credential the database has accepted. PHP has no attribute for
     * return values, so cachedString() cannot redact the value it returns.
     */
    private function remember(
        string $key,
        #[\SensitiveParameter]
        string $value,
        int $ttlSeconds,
    ): void {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            return;
        }

        $item = $this->cache->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttlSeconds);

        $this->cache->save($item);
    }

    private function forget(string $key): void
    {
        $this->cache?->deleteItem($key);
    }

    // Hashing keeps the key length constant and removes the PSR-6 reserved characters
    // {}()/\@:. An RDS endpoint host name contains the region, so the token key needs
    // no region segment. https://www.php-fig.org/psr/psr-6/

    private function tokenKey(string $host, int $port, string $username): string
    {
        return 'rds_iam_token.v1.'.hash('sha256', sprintf('%s:%d|%s', $host, $port, $username));
    }

    private function passwordKey(string $secretArn): string
    {
        return 'rds_secret_password.v1.'.hash('sha256', $secretArn);
    }
}
