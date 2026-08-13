<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Integration;

use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TacticMedia\RdsAuth\RdsAuthDriver;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;
use TacticMedia\RdsAuth\Tests\Support\FrozenClock;

/**
 * MySQL caps stored passwords at 256 characters and a token exceeds that, so
 * the PostgreSQL token-as-role-password technique cannot work here. On real RDS
 * the AWSAuthenticationPlugin validates the token itself. These tests prove the
 * wiring against a real MySQL instead: the IAM username reaches the server, the
 * token travels as the password, and rejection drives the cache correctly.
 *
 * @internal
 */
#[CoversClass(RdsAuthDriver::class)]
#[CoversClass(RdsIamTokenGenerator::class)]
#[CoversClass(RdsIamTokenProvider::class)]
final class MysqlIamTokenConnectionTest extends IntegrationTestCase
{
    private const string REGION = 'ap-southeast-2';

    private \PDO $admin;

    private string $role;

    private RdsIamTokenGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = self::mysqlAdminPdo();
        $this->role = 'iam_app_'.bin2hex(random_bytes(4));
        $this->generator = new RdsIamTokenGenerator(
            new Credentials('AKIDEXAMPLE', 'example-secret'),
            new FrozenClock(new \DateTimeImmutable()),
        );

        $this->admin->exec(sprintf("CREATE USER %s@'%%' IDENTIFIED BY 'not-the-token'", $this->role));
        $this->admin->exec(sprintf("GRANT SELECT ON %s.* TO %s@'%%'", self::mysqlDatabase(), $this->role));
    }

    protected function tearDown(): void
    {
        if (isset($this->admin, $this->role)) {
            $this->admin->exec(sprintf("DROP USER IF EXISTS %s@'%%'", $this->role));
        }
    }

    #[TestDox('Sends the IAM username and the token as the password to the real MySQL server')]
    public function testTokenTravelsAsPasswordToTheServer(): void
    {
        self::assertGreaterThan(256, strlen($this->freshToken()));

        $connection = self::connectionThrough($this->middleware(new ArrayAdapter()), $this->mysqlParams());

        try {
            $connection->fetchOne('SELECT 1');
            self::fail('The token cannot match the stored password, so the connection must fail.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            // 1045 with "(using password: YES)" proves the server saw the substituted
            // user and a transmitted password.
            self::assertStringContainsString(sprintf("Access denied for user '%s'", $this->role), $exception->getMessage());
            self::assertStringContainsString('using password: YES', $exception->getMessage());
        }
    }

    #[TestDox('Evicts a stale cached token after the real MySQL rejection and leaves the cache cold when the fresh token also fails')]
    public function testStaleCachedTokenIsEvicted(): void
    {
        $cache = new ArrayAdapter();
        $staleItem = $cache->getItem($this->cacheKey());
        $staleItem->set('stale-token-the-server-rejects');

        $cache->save($staleItem);

        $connection = self::connectionThrough($this->middleware($cache), $this->mysqlParams());

        try {
            $connection->fetchOne('SELECT 1');
            self::fail('Both the cached and the fresh token must be rejected.');
        } catch (\Doctrine\DBAL\Exception) {
        }

        self::assertFalse($cache->getItem($this->cacheKey())->isHit());
    }

    /** @return array<string, mixed> */
    private function mysqlParams(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => self::mysqlHost(),
            'port' => self::mysqlPort(),
            'dbname' => self::mysqlDatabase(),
            'driverOptions' => self::mysqlDriverOptions(),
        ];
    }

    private function freshToken(): string
    {
        return $this->generator->createToken(
            sprintf('%s:%d', self::mysqlHost(), self::mysqlPort()),
            self::REGION,
            $this->role,
        );
    }

    private function middleware(ArrayAdapter $cache): RdsAuthMiddleware
    {
        return new RdsAuthMiddleware(
            new RdsIamTokenProvider(self::REGION, $this->generator),
            new RdsSecretPasswordProvider(self::REGION, self::secretsManagerClient()),
            iamUsername: $this->role,
            secretArn: null,
            cache: $cache,
        );
    }

    private function cacheKey(): string
    {
        return 'rds_iam_token.v1.'.hash('sha256', sprintf('%s:%d|%s', self::mysqlHost(), self::mysqlPort(), $this->role));
    }
}
