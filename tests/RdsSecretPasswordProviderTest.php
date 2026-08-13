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
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;

/**
 * @internal
 */
#[CoversClass(RdsSecretPasswordProvider::class)]
final class RdsSecretPasswordProviderTest extends TestCase
{
    private const string SECRET_ARN = 'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc';

    /** @var list<MockResponse> */
    private array $responses = [];

    private RdsSecretPasswordProvider $provider;

    protected function setUp(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => array_shift($this->responses) ?? throw new \LogicException('Unexpected Secrets Manager call.'));
        $this->provider = new RdsSecretPasswordProvider('ap-southeast-2', new SecretsManagerClient(
            ['region' => 'ap-southeast-2'],
            new Credentials('AKIDEXAMPLE', 'example-secret'),
            $http,
        ));
    }

    #[TestDox('Returns the password key of the secret JSON')]
    public function testReturnsThePassword(): void
    {
        $this->responses[] = $this->secretValueResponse('{"username":"postgres","password":"s3cret"}');

        self::assertSame('s3cret', $this->provider->freshPassword(self::SECRET_ARN));
    }

    #[TestDox('Rejects a secret with no SecretString')]
    public function testRejectsMissingSecretString(): void
    {
        $this->responses[] = new MockResponse('{}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('has no SecretString value');
        $this->provider->freshPassword(self::SECRET_ARN);
    }

    #[TestDox('Rejects a secret that is not JSON')]
    public function testRejectsInvalidJson(): void
    {
        $this->responses[] = $this->secretValueResponse('not-json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('is not valid JSON');
        $this->provider->freshPassword(self::SECRET_ARN);
    }

    #[TestDox('Rejects a secret without a string password key')]
    public function testRejectsMissingPasswordKey(): void
    {
        $this->responses[] = $this->secretValueResponse('{"username":"postgres"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('has no string "password" key');
        $this->provider->freshPassword(self::SECRET_ARN);
    }

    #[TestDox('Wraps an AWS failure with the secret ARN')]
    public function testWrapsAwsFailure(): void
    {
        $this->responses[] = new MockResponse('{"__type":"AccessDeniedException","message":"Access denied"}', ['http_code' => 400]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Cannot read RDS master secret "'.self::SECRET_ARN.'"');
        $this->provider->freshPassword(self::SECRET_ARN);
    }

    private function secretValueResponse(string $secretString): MockResponse
    {
        return new MockResponse(json_encode(['SecretString' => $secretString], JSON_THROW_ON_ERROR));
    }
}
