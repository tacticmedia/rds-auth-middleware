<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TacticMedia\RdsAuth\RdsAuthDriver;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;

/**
 * @internal
 */
#[CoversClass(RdsAuthDriver::class)]
#[CoversClass(RdsSecretPasswordProvider::class)]
final class ManagedPasswordRotationTest extends IntegrationTestCase
{
    private const string CONFIGURED_PASSWORD = 'configured-password';

    private const string ROTATED_PASSWORD = 'rotated-password';

    private \PDO $admin;

    private string $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = self::adminPdo();
        $this->role = 'secret_app_'.bin2hex(random_bytes(4));
        $this->admin->exec(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $this->role,
            $this->admin->quote(self::CONFIGURED_PASSWORD),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->admin, $this->role)) {
            $this->admin->exec(sprintf('DROP ROLE IF EXISTS %s', $this->role));
        }
    }

    #[TestDox('Connects with the configured password and reads no secret')]
    public function testConnectsWithConfiguredPassword(): void
    {
        $connection = self::connectionThrough($this->middleware('arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:absent'), [
            'user' => $this->role,
            'password' => self::CONFIGURED_PASSWORD,
        ]);

        // The absent secret ARN turns any secret read into a RuntimeException.
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    #[TestDox('Reads the rotated password from Secrets Manager after the real 28P01 rejection and reconnects')]
    public function testRefreshesPasswordAfterRotation(): void
    {
        $secretArn = $this->createSecret(self::CONFIGURED_PASSWORD);

        $this->admin->exec(sprintf(
            'ALTER ROLE %s PASSWORD %s',
            $this->role,
            $this->admin->quote(self::ROTATED_PASSWORD),
        ));
        self::secretsManagerClient()->putSecretValue([
            'SecretId' => $secretArn,
            'SecretString' => json_encode(['username' => $this->role, 'password' => self::ROTATED_PASSWORD], JSON_THROW_ON_ERROR),
        ]);

        $cache = new ArrayAdapter();
        $connection = self::connectionThrough($this->middleware($secretArn, $cache), [
            'user' => $this->role,
            'password' => self::CONFIGURED_PASSWORD,
        ]);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertSame(
            self::ROTATED_PASSWORD,
            $cache->getItem('rds_secret_password.v1.'.hash('sha256', $secretArn))->get(),
        );
    }

    #[TestDox('Propagates a non-password failure without a secret read')]
    public function testNonPasswordFailurePropagatesWithoutSecretRead(): void
    {
        $connection = self::connectionThrough($this->middleware('arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:absent'), [
            'user' => $this->role,
            'password' => self::CONFIGURED_PASSWORD,
            'dbname' => 'database_that_does_not_exist',
        ]);

        try {
            $connection->fetchOne('SELECT 1');
            self::fail('Connecting to an absent database must fail.');
        } catch (\Throwable $throwable) {
            // A secret read against the absent ARN would surface as a RuntimeException instead.
            self::assertNotInstanceOf(\RuntimeException::class, $throwable);
            self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $throwable);
        }
    }

    private function middleware(string $secretArn, ?ArrayAdapter $cache = null): RdsAuthMiddleware
    {
        return new RdsAuthMiddleware(
            new RdsIamTokenProvider('ap-southeast-2'),
            new RdsSecretPasswordProvider('ap-southeast-2', self::secretsManagerClient()),
            iamUsername: null,
            secretArn: $secretArn,
            cache: $cache,
        );
    }

    private function createSecret(string $password): string
    {
        $result = self::secretsManagerClient()->createSecret([
            'Name' => 'rds-master-'.bin2hex(random_bytes(4)),
            'SecretString' => json_encode(['username' => $this->role, 'password' => $password], JSON_THROW_ON_ERROR),
        ]);

        $arn = $result->getArn();
        self::assertNotNull($arn);

        return $arn;
    }
}
