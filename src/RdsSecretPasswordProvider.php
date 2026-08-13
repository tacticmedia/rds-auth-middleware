<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\SecretsManager\SecretsManagerClient;

/**
 * Reads the master password from the RDS-managed secret JSON. Every read is a network
 * request, so the driver calls this only after a rejection.
 *
 * @see https://docs.aws.amazon.com/secretsmanager/latest/userguide/reference_secret_json_structure.html
 */
final readonly class RdsSecretPasswordProvider
{
    private SecretsManagerClient $secrets;

    public function __construct(string $region, ?SecretsManagerClient $secrets = null)
    {
        $this->secrets = $secrets ?? new SecretsManagerClient(['region' => $region]);
    }

    public function freshPassword(string $secretArn): string
    {
        try {
            // The response is lazy; getSecretString() resolves it, so it must stay inside the try.
            $secretString = $this->secrets->getSecretValue(['SecretId' => $secretArn])->getSecretString();
        } catch (AsyncAwsException $asyncAwsException) {
            $reason = $asyncAwsException instanceof HttpException
                ? ($asyncAwsException->getAwsMessage() ?? $asyncAwsException->getMessage())
                : $asyncAwsException->getMessage();

            throw new \RuntimeException(sprintf('Cannot read RDS master secret "%s": %s', $secretArn, $reason), (int) $asyncAwsException->getCode(), previous: $asyncAwsException);
        }

        if (null === $secretString || '' === $secretString) {
            throw new \RuntimeException(sprintf('RDS master secret "%s" has no SecretString value.', $secretArn));
        }

        try {
            $data = json_decode($secretString, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException(sprintf('RDS master secret "%s" is not valid JSON: %s', $secretArn, $jsonException->getMessage()), $jsonException->getCode(), previous: $jsonException);
        }

        if (!is_array($data) || !is_string($data['password'] ?? null)) {
            throw new \RuntimeException(sprintf('RDS master secret "%s" has no string "password" key.', $secretArn));
        }

        return $data['password'];
    }
}
