<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

/**
 * Dispatched from managed-password mode when the database rejects the configured
 * password and accepts the current Secrets Manager password. The deployed
 * configuration is outdated at that point; a redeployment reloads it.
 *
 * Carries no credential. A null property means the connection parameters did not
 * supply the value; no engine default is substituted.
 *
 * {@see RdsAuthDriver::__construct()} for the dispatcher seam.
 */
final readonly class ConfiguredPasswordOutdated
{
    public function __construct(
        public string $secretArn,
        public ?string $host,
        public ?int $port,
        public ?string $dbname,
        public ?string $user,
        public ?string $sqlState,
    ) {
    }
}
