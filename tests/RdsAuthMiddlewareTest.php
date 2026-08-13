<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TacticMedia\RdsAuth\RdsAuthDriver;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;
use TacticMedia\RdsAuth\Tests\Support\FakeDriver;

/**
 * @internal
 */
#[CoversClass(RdsAuthMiddleware::class)]
final class RdsAuthMiddlewareTest extends TestCase
{
    #[TestDox('wrap() hands the configuration to an RdsAuthDriver around the wrapped driver')]
    public function testWrapBuildsConfiguredDriver(): void
    {
        $middleware = new RdsAuthMiddleware(
            new RdsIamTokenProvider('ap-southeast-2', new RdsIamTokenGenerator(new Credentials('AKIDEXAMPLE', 'example-secret'))),
            new RdsSecretPasswordProvider('ap-southeast-2'),
            iamUsername: 'app',
            secretArn: null,
        );
        $fake = new FakeDriver(null);

        $driver = $middleware->wrap($fake);

        self::assertInstanceOf(RdsAuthDriver::class, $driver);
        $driver->connect(['host' => 'db.example.com', 'port' => 5432]);
        self::assertSame('app', $fake->attempts[0]['user']);
    }
}
