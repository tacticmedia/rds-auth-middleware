<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\SecretsManager\SecretsManagerClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TacticMedia\RdsAuth\ConfiguredPasswordOutdated;
use TacticMedia\RdsAuth\RdsAuthDriver;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;
use TacticMedia\RdsAuth\Tests\Support\FakeDriver;
use TacticMedia\RdsAuth\Tests\Support\RecordingEventDispatcher;
use TacticMedia\RdsAuth\Tests\Support\StubDriverException;

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

    #[TestDox('wrap() threads the event dispatcher through to the driver')]
    public function testWrapThreadsEventDispatcher(): void
    {
        $secretsManagerHttp = new MockHttpClient(new MockResponse(json_encode(['SecretString' => '{"password":"rotated"}'], JSON_THROW_ON_ERROR)));
        $dispatcher = new RecordingEventDispatcher();
        $middleware = new RdsAuthMiddleware(
            new RdsIamTokenProvider('ap-southeast-2', new RdsIamTokenGenerator(new Credentials('AKIDEXAMPLE', 'example-secret'))),
            new RdsSecretPasswordProvider(
                'ap-southeast-2',
                new SecretsManagerClient(['region' => 'ap-southeast-2'], new Credentials('AKIDEXAMPLE', 'example-secret'), $secretsManagerHttp),
            ),
            iamUsername: null,
            secretArn: 'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc',
            eventDispatcher: $dispatcher,
        );
        $fake = new FakeDriver(new StubDriverException('auth failed', '28P01'), null);

        $middleware->wrap($fake)->connect(['host' => 'db.example.com', 'password' => 'injected']);

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(ConfiguredPasswordOutdated::class, $dispatcher->events[0]);
    }
}
