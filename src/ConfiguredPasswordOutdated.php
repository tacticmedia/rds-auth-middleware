<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

/**
 * Dispatched from managed-password mode when the database rejects a password, accepts
 * the current Secrets Manager password, and that password differs from the configured
 * one. The deployed configuration is outdated at that point; a redeployment reloads it.
 *
 * Carries no credential. $sqlState belongs to the rejected password, cached or
 * configured. A null property means the connection parameters did not supply the
 * value; no engine default is substituted.
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
