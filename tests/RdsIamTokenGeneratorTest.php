<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;

/**
 * Signature correctness lives in {@see RdsIamTokenGeneratorReferenceTest}.
 *
 * @internal
 */
#[CoversClass(RdsIamTokenGenerator::class)]
final class RdsIamTokenGeneratorTest extends TestCase
{
    #[TestDox('Rejects token creation when no AWS credentials resolve')]
    public function testRejectsUnresolvableCredentials(): void
    {
        $noCredentials = new class implements CredentialProvider {
            public function getCredentials(Configuration $configuration): ?Credentials
            {
                return null;
            }
        };

        $generator = new RdsIamTokenGenerator($noCredentials);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve AWS credentials');
        $generator->createToken('db.example.com:5432', 'ap-southeast-2', 'app_user');
    }
}
