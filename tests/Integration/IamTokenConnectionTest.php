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
 * RDS validates the token signature; PostgreSQL outside AWS cannot. Setting the
 * role password to the deterministic token makes the connection succeed only
 * when the exact token string reaches the server as the password, over SSL.
 *
 * @internal
 */
#[CoversClass(RdsAuthDriver::class)]
#[CoversClass(RdsIamTokenGenerator::class)]
#[CoversClass(RdsIamTokenProvider::class)]
final class IamTokenConnectionTest extends IntegrationTestCase
{
    private const string REGION = 'ap-southeast-2';

    private \PDO $admin;

    private string $role;

    private RdsIamTokenGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = self::adminPdo();
        $this->role = 'iam_app_'.bin2hex(random_bytes(4));
        $this->generator = new RdsIamTokenGenerator(
            new Credentials('AKIDEXAMPLE', 'example-secret'),
            new FrozenClock(new \DateTimeImmutable()),
        );

        $this->admin->exec(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $this->role,
            $this->admin->quote($this->freshToken()),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->admin, $this->role)) {
            $this->admin->exec(sprintf('DROP ROLE IF EXISTS %s', $this->role));
        }
    }

    #[TestDox('Connects over SSL with the token as the password, byte for byte')]
    public function testConnectsWithTokenAsPassword(): void
    {
        $cache = new ArrayAdapter();
        $connection = self::connectionThrough($this->middleware($cache));

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertTrue($connection->fetchOne('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()'));
        self::assertSame($this->freshToken(), $cache->getItem($this->cacheKey())->get());
    }

    #[TestDox('Evicts a stale cached token after the real rejection, signs a fresh one, and reconnects')]
    public function testRecoversFromStaleCachedToken(): void
    {
        $cache = new ArrayAdapter();
        $staleItem = $cache->getItem($this->cacheKey());
        $staleItem->set('stale-token-the-server-rejects');

        $cache->save($staleItem);

        $connection = self::connectionThrough($this->middleware($cache));

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertSame($this->freshToken(), $cache->getItem($this->cacheKey())->get());
    }

    /** The frozen clock and static credentials make every call return the same token. */
    private function freshToken(): string
    {
        return $this->generator->createToken(
            sprintf('%s:%d', self::pgHost(), self::pgPort()),
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
        return 'rds_iam_token.v1.'.hash('sha256', sprintf('%s:%d|%s', self::pgHost(), self::pgPort(), $this->role));
    }
}
