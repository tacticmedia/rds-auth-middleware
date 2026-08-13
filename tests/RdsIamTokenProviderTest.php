<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\RdsIamTokenProvider;

/**
 * @internal
 */
#[CoversClass(RdsIamTokenProvider::class)]
#[CoversClass(RdsIamTokenGenerator::class)]
final class RdsIamTokenProviderTest extends TestCase
{
    #[TestDox('Signs a token for the endpoint, region, and user with no network call')]
    public function testSignsAToken(): void
    {
        $provider = new RdsIamTokenProvider(
            'ap-southeast-2',
            new RdsIamTokenGenerator(new Credentials('AKIDEXAMPLE', 'example-secret')),
        );

        $token = $provider->freshToken('db.example.com', 5432, 'app');

        self::assertStringStartsWith('db.example.com:5432/', $token);
        self::assertStringContainsString('Action=connect', $token);
        self::assertStringContainsString('DBUser=app', $token);
        self::assertStringContainsString('X-Amz-Signature=', $token);
        self::assertStringContainsString('X-Amz-Expires=900', $token);
        self::assertStringContainsString('ap-southeast-2', $token);
    }
}
