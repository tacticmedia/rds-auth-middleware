<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\SecretsManager\SecretsManagerClient;
use Doctrine\DBAL\Driver\Exception as DriverException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TacticMedia\RdsAuth\DatabaseEngine;
use TacticMedia\RdsAuth\RdsAuthDriver;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;
use TacticMedia\RdsAuth\Tests\Support\FakeDriver;
use TacticMedia\RdsAuth\Tests\Support\StubDriverException;

/**
 * @internal
 */
#[CoversClass(RdsAuthDriver::class)]
final class RdsAuthDriverTest extends TestCase
{
    private const string SECRET_ARN = 'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc';

    /** @var array<string, mixed> */
    private const array PARAMS = [
        'host' => 'db.example.com',
        'port' => 5432,
        'dbname' => 'app',
        'user' => 'postgres',
        'password' => 'injected',
    ];

    private ArrayAdapter $cache;

    /** @var list<MockResponse> */
    private array $secretsManagerResponses = [];

    private MockHttpClient $secretsManagerHttp;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->secretsManagerHttp = new MockHttpClient(fn (): MockResponse => array_shift($this->secretsManagerResponses) ?? throw new \LogicException('Unexpected Secrets Manager call.'));
    }

    #[TestDox('Neither mode configured: params pass through untouched')]
    public function testPassThrough(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: null, secretArn: null);

        $connection = $driver->connect(self::PARAMS);

        self::assertSame($fake->connection, $connection);
        self::assertSame([self::PARAMS], $fake->attempts);
    }

    #[TestDox('Empty strings disable both modes like null does')]
    public function testEmptyStringsDisableBothModes(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: '', secretArn: '');

        $driver->connect(self::PARAMS);

        self::assertSame([self::PARAMS], $fake->attempts);
    }

    #[TestDox('IAM mode replaces user and password with a freshly signed token')]
    public function testIamModeSwapsCredentials(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $connection = $driver->connect(self::PARAMS);

        self::assertSame($fake->connection, $connection);
        self::assertCount(1, $fake->attempts);
        $attempt = $fake->attempts[0];
        self::assertSame('app', $attempt['user']);
        self::assertSame('require', $attempt['sslmode']);
        self::assertIsString($attempt['password']);
        self::assertStringContainsString('db.example.com:5432', $attempt['password']);
        self::assertStringContainsString('X-Amz-Signature=', $attempt['password']);
    }

    #[TestDox('IAM mode on MySQL defaults the port to 3306 and injects no sslmode')]
    public function testMysqlIamModeShapesParams(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null, engine: DatabaseEngine::Mysql);

        $driver->connect(['host' => 'db.example.com', 'dbname' => 'app']);

        $attempt = $fake->attempts[0];
        self::assertSame('app', $attempt['user']);
        self::assertArrayNotHasKey('sslmode', $attempt);
        self::assertIsString($attempt['password']);
        self::assertStringStartsWith('db.example.com:3306/', $attempt['password']);
    }

    #[TestDox('IAM mode on PostgreSQL defaults the port to 5432')]
    public function testPostgresIamModeDefaultsPort(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $driver->connect(['host' => 'db.example.com', 'dbname' => 'app']);

        $attempt = $fake->attempts[0];
        self::assertSame('require', $attempt['sslmode']);
        self::assertIsString($attempt['password']);
        self::assertStringStartsWith('db.example.com:5432/', $attempt['password']);
    }

    #[TestDox('An accepted token is cached and the next connect reuses the cache')]
    public function testAcceptedTokenIsCached(): void
    {
        $fake = new FakeDriver(null, null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $driver->connect(self::PARAMS);
        self::assertTrue($this->cache->getItem($this->tokenKey())->isHit());

        $this->seedCache($this->tokenKey(), 'sentinel-token');
        $driver->connect(self::PARAMS);

        self::assertSame('sentinel-token', $fake->attempts[1]['password']);
    }

    #[TestDox('A rejected cached token is dropped, re-minted, and retried once')]
    public function testRejectedCachedTokenIsRemintedOnce(): void
    {
        $this->seedCache($this->tokenKey(), 'stale-token');
        $fake = new FakeDriver(new StubDriverException('auth failed'), null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $connection = $driver->connect(self::PARAMS);

        self::assertSame($fake->connection, $connection);
        self::assertCount(2, $fake->attempts);
        self::assertSame('stale-token', $fake->attempts[0]['password']);
        $retryPassword = $fake->attempts[1]['password'];
        self::assertIsString($retryPassword);
        self::assertStringContainsString('X-Amz-Signature=', $retryPassword);
        self::assertSame($retryPassword, $this->cache->getItem($this->tokenKey())->get());
    }

    #[TestDox('A failure on a freshly minted token is not retried and nothing stays cached')]
    public function testFreshTokenFailureIsFinal(): void
    {
        $failure = new StubDriverException('database is down');
        $fake = new FakeDriver($failure);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $driverException = null;
        try {
            $driver->connect(self::PARAMS);
        } catch (DriverException $driverException) {
        }

        self::assertSame($failure, $driverException);
        self::assertCount(1, $fake->attempts);
        self::assertFalse($this->cache->getItem($this->tokenKey())->isHit());
    }

    #[TestDox('Two IAM failures in a row surface the second exception and leave the cache cold')]
    public function testSecondIamFailureSurfaces(): void
    {
        $this->seedCache($this->tokenKey(), 'stale-token');
        $first = new StubDriverException('first failure');
        $second = new StubDriverException('second failure');
        $fake = new FakeDriver($first, $second);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null);

        $driverException = null;
        try {
            $driver->connect(self::PARAMS);
        } catch (DriverException $driverException) {
        }

        self::assertSame($second, $driverException);
        self::assertCount(2, $fake->attempts);
        self::assertFalse($this->cache->getItem($this->tokenKey())->isHit());
    }

    #[TestDox('Secret mode connects with the injected password and never calls Secrets Manager')]
    public function testSecretModeUsesInjectedPasswordFirst(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN);

        $driver->connect(self::PARAMS);

        self::assertSame('injected', $fake->attempts[0]['password']);
        self::assertSame(0, $this->secretsManagerHttp->getRequestsCount());
    }

    #[TestDox('A warm cached password is preferred over the injected one')]
    public function testCachedPasswordBeatsInjected(): void
    {
        $this->seedCache($this->passwordKey(), 'cached-password');
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN);

        $driver->connect(self::PARAMS);

        self::assertSame('cached-password', $fake->attempts[0]['password']);
    }

    #[TestDox('A rejected cached password is dropped, re-read from the secret, and retried once')]
    public function testRejectedCachedPasswordIsDropped(): void
    {
        $this->seedCache($this->passwordKey(), 'stale-password');
        $this->secretsManagerResponses[] = $this->secretValueResponse('{"username":"postgres","password":"rotated"}');
        $fake = new FakeDriver(new StubDriverException('auth failed', '28P01'), null);
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN);

        $connection = $driver->connect(self::PARAMS);

        self::assertSame($fake->connection, $connection);
        self::assertSame('stale-password', $fake->attempts[0]['password']);
        self::assertSame('rotated', $fake->attempts[1]['password']);
        self::assertSame('rotated', $this->cache->getItem($this->passwordKey())->get());
    }

    #[DataProvider('passwordFailures')]
    #[TestDox('A rejected password is re-read from Secrets Manager, retried once, and cached')]
    public function testRejectedPasswordIsRefreshed(StubDriverException $failure): void
    {
        $this->secretsManagerResponses[] = $this->secretValueResponse('{"username":"postgres","password":"rotated"}');
        $fake = new FakeDriver($failure, null);
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN);

        $connection = $driver->connect(self::PARAMS);

        self::assertSame($fake->connection, $connection);
        self::assertCount(2, $fake->attempts);
        self::assertSame('rotated', $fake->attempts[1]['password']);
        self::assertSame('rotated', $this->cache->getItem($this->passwordKey())->get());
    }

    /** @return iterable<string, array{StubDriverException}> */
    public static function passwordFailures(): iterable
    {
        yield 'SQLSTATE 28P01' => [new StubDriverException('auth failed', '28P01')];
        yield 'SQLSTATE 28000' => [new StubDriverException('auth failed', '28000')];
        yield 'message under a generic SQLSTATE' => [new StubDriverException('SQLSTATE[08006] FATAL: password authentication failed for user "postgres"', '08006')];
        yield 'MySQL 1045 driver code' => [new StubDriverException('Access denied', 'HY000', 1045)];
        yield 'MySQL access-denied message under a generic SQLSTATE' => [new StubDriverException("SQLSTATE[HY000] [1045] Access denied for user 'app'@'10.0.0.1' (using password: YES)", 'HY000')];
    }

    #[DataProvider('nonPasswordFailures')]
    #[TestDox('A non-password connect failure is rethrown without touching Secrets Manager')]
    public function testNonPasswordFailureIsNotRetried(StubDriverException $failure): void
    {
        $fake = new FakeDriver($failure);
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN);

        $driverException = null;
        try {
            $driver->connect(self::PARAMS);
        } catch (DriverException $driverException) {
        }

        self::assertSame($failure, $driverException);
        self::assertCount(1, $fake->attempts);
        self::assertSame(0, $this->secretsManagerHttp->getRequestsCount());
    }

    /** @return iterable<string, array{StubDriverException}> */
    public static function nonPasswordFailures(): iterable
    {
        yield 'PostgreSQL server unreachable' => [new StubDriverException('could not connect to server', '08006')];
        yield 'MySQL 1044 missing database privilege' => [new StubDriverException("SQLSTATE[42000] [1044] Access denied for user 'app'@'%' to database 'other'", '42000', 1044)];
    }

    #[TestDox('IAM mode wins when both modes are configured')]
    public function testIamModeWinsOverSecretMode(): void
    {
        $fake = new FakeDriver(null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: self::SECRET_ARN);

        $driver->connect(self::PARAMS);

        self::assertSame('app', $fake->attempts[0]['user']);
        self::assertIsString($fake->attempts[0]['password']);
        self::assertStringContainsString('X-Amz-Signature=', $fake->attempts[0]['password']);
        self::assertSame(0, $this->secretsManagerHttp->getRequestsCount());
    }

    #[TestDox('Without a cache the IAM path signs a token on every connect')]
    public function testIamWithoutCacheMintsPerConnect(): void
    {
        $fake = new FakeDriver(null, null);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null, cached: false);

        $driver->connect(self::PARAMS);
        $driver->connect(self::PARAMS);

        self::assertCount(2, $fake->attempts);
        foreach ($fake->attempts as $attempt) {
            self::assertIsString($attempt['password']);
            self::assertStringContainsString('X-Amz-Signature=', $attempt['password']);
        }
    }

    #[TestDox('Without a cache an IAM connect failure stays a single attempt')]
    public function testIamWithoutCacheFailureIsSingleAttempt(): void
    {
        $failure = new StubDriverException('database is down');
        $fake = new FakeDriver($failure);
        $driver = $this->driver($fake, iamUsername: 'app', secretArn: null, cached: false);

        $driverException = null;
        try {
            $driver->connect(self::PARAMS);
        } catch (DriverException $driverException) {
        }

        self::assertSame($failure, $driverException);
        self::assertCount(1, $fake->attempts);
    }

    #[TestDox('Without a cache a rejected password is re-read from Secrets Manager on every connect')]
    public function testSecretWithoutCacheRefreshesEveryConnect(): void
    {
        $this->secretsManagerResponses[] = $this->secretValueResponse('{"password":"rotated-1"}');
        $this->secretsManagerResponses[] = $this->secretValueResponse('{"password":"rotated-2"}');
        $fake = new FakeDriver(
            new StubDriverException('auth failed', '28P01'),
            null,
            new StubDriverException('auth failed', '28P01'),
            null,
        );
        $driver = $this->driver($fake, iamUsername: null, secretArn: self::SECRET_ARN, cached: false);

        $driver->connect(self::PARAMS);
        $driver->connect(self::PARAMS);

        self::assertCount(4, $fake->attempts);
        self::assertSame('injected', $fake->attempts[0]['password']);
        self::assertSame('rotated-1', $fake->attempts[1]['password']);
        self::assertSame('injected', $fake->attempts[2]['password']);
        self::assertSame('rotated-2', $fake->attempts[3]['password']);
        self::assertSame([], $this->secretsManagerResponses);
    }

    private function driver(FakeDriver $fake, ?string $iamUsername, ?string $secretArn, bool $cached = true, DatabaseEngine $engine = DatabaseEngine::Postgres): RdsAuthDriver
    {
        $tokens = new RdsIamTokenProvider(
            'ap-southeast-2',
            new RdsIamTokenGenerator(new Credentials('AKIDEXAMPLE', 'example-secret')),
        );
        $passwords = new RdsSecretPasswordProvider(
            'ap-southeast-2',
            new SecretsManagerClient(
                ['region' => 'ap-southeast-2'],
                new Credentials('AKIDEXAMPLE', 'example-secret'),
                $this->secretsManagerHttp,
            ),
        );

        return new RdsAuthDriver($fake, $tokens, $passwords, $iamUsername, $secretArn, $cached ? $this->cache : null, $engine);
    }

    private function secretValueResponse(string $secretString): MockResponse
    {
        return new MockResponse(json_encode(['SecretString' => $secretString], JSON_THROW_ON_ERROR));
    }

    // The key formats are a contract with shared caches: a format change invalidates
    // every stored entry. These tests assert the exact formats.

    private function tokenKey(): string
    {
        return 'rds_iam_token.v1.'.hash('sha256', 'db.example.com:5432|app');
    }

    private function passwordKey(): string
    {
        return 'rds_secret_password.v1.'.hash('sha256', self::SECRET_ARN);
    }

    private function seedCache(string $key, string $value): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);

        $this->cache->save($item);
    }
}
