<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

/**
 * Generates RDS IAM authentication tokens. The AsyncAws default credential chain
 * (environment, web identity, ini files, ECS, IMDS) resolves the instance or task
 * role; that role requires the rds-db:connect permission.
 *
 * @see https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.IAMPolicy.html
 */
final readonly class RdsIamTokenProvider
{
    private RdsIamTokenGenerator $generator;

    public function __construct(
        private string $region,
        ?RdsIamTokenGenerator $generator = null,
    ) {
        $this->generator = $generator ?? new RdsIamTokenGenerator();
    }

    /** Signs locally with SigV4 without calling RDS; credential resolution can add one IMDS round trip. */
    public function freshToken(string $host, int $port, string $username): string
    {
        return $this->generator->createToken(sprintf('%s:%d', $host, $port), $this->region, $username);
    }
}
