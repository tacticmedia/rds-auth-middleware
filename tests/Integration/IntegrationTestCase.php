<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Integration;

use AsyncAws\SecretsManager\SecretsManagerClient;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the compose integration profile (PostgreSQL with SSL, moto).
 * Gated by RDS_AUTH_INTEGRATION_TESTS=1; connection targets override through
 * the RDS_AUTH_IT_* environment variables.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if ('1' !== getenv('RDS_AUTH_INTEGRATION_TESTS')) {
            self::markTestSkipped('Set RDS_AUTH_INTEGRATION_TESTS=1 and start: docker compose --profile integration up -d --wait postgres mysql moto');
        }
    }

    protected static function pgHost(): string
    {
        return getenv('RDS_AUTH_IT_PG_HOST') ?: '127.0.0.1';
    }

    protected static function pgPort(): int
    {
        return (int) (getenv('RDS_AUTH_IT_PG_PORT') ?: 55432);
    }

    protected static function pgDatabase(): string
    {
        return getenv('RDS_AUTH_IT_PG_DATABASE') ?: 'app';
    }

    protected static function pgAdminUser(): string
    {
        return getenv('RDS_AUTH_IT_PG_ADMIN_USER') ?: 'admin';
    }

    protected static function pgAdminPassword(): string
    {
        return getenv('RDS_AUTH_IT_PG_ADMIN_PASSWORD') ?: 'admin';
    }

    protected static function motoEndpoint(): string
    {
        return getenv('RDS_AUTH_IT_MOTO_ENDPOINT') ?: 'http://127.0.0.1:5566';
    }

    protected static function mysqlHost(): string
    {
        return getenv('RDS_AUTH_IT_MYSQL_HOST') ?: '127.0.0.1';
    }

    protected static function mysqlPort(): int
    {
        return (int) (getenv('RDS_AUTH_IT_MYSQL_PORT') ?: 53306);
    }

    protected static function mysqlDatabase(): string
    {
        return getenv('RDS_AUTH_IT_MYSQL_DATABASE') ?: 'app';
    }

    protected static function mysqlAdminUser(): string
    {
        return getenv('RDS_AUTH_IT_MYSQL_ADMIN_USER') ?: 'root';
    }

    protected static function mysqlAdminPassword(): string
    {
        return getenv('RDS_AUTH_IT_MYSQL_ADMIN_PASSWORD') ?: 'admin';
    }

    /** The CA file lands here through the mysql-init.sh export volume. */
    protected static function mysqlCaFile(): string
    {
        return getenv('RDS_AUTH_IT_MYSQL_CA_FILE') ?: __DIR__.'/docker/mysql-export/ca.pem';
    }

    /**
     * pdo_mysql enables TLS only with a CA file configured, and caching_sha2_password
     * refuses the first authentication of a user over an insecure channel.
     *
     * @return array<int, mixed>
     */
    protected static function mysqlDriverOptions(): array
    {
        if (class_exists(\Pdo\Mysql::class)) {
            return [
                \Pdo\Mysql::ATTR_SSL_CA => self::mysqlCaFile(),
                \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];
        }

        return [
            \PDO::MYSQL_ATTR_SSL_CA => self::mysqlCaFile(),
            \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ];
    }

    protected static function mysqlAdminPdo(): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', self::mysqlHost(), self::mysqlPort(), self::mysqlDatabase());

        return new \PDO($dsn, self::mysqlAdminUser(), self::mysqlAdminPassword(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ...self::mysqlDriverOptions(),
        ]);
    }

    protected static function adminPdo(): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            self::pgHost(),
            self::pgPort(),
            self::pgDatabase(),
        );

        return new \PDO($dsn, self::pgAdminUser(), self::pgAdminPassword(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected static function secretsManagerClient(): SecretsManagerClient
    {
        return new SecretsManagerClient([
            'endpoint' => self::motoEndpoint(),
            'region' => 'ap-southeast-2',
            'accessKeyId' => 'testing',
            'accessKeySecret' => 'testing',
        ]);
    }

    /** @param array<string, mixed> $extraParams */
    protected static function connectionThrough(Middleware $middleware, array $extraParams = []): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => self::pgHost(),
            'port' => self::pgPort(),
            'dbname' => self::pgDatabase(),
            ...$extraParams,
        ], $configuration);
    }
}
