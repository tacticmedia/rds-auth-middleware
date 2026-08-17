<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class RdsAuthMiddleware implements Middleware
{
    /**
     * A set $iamUsername selects IAM token authentication; otherwise a set $secretArn
     * selects managed-password authentication; with neither, connection parameters pass
     * through unchanged. One build therefore runs in every environment. For a null
     * $cache {@see RdsAuthDriver::__construct()}.
     *
     * Listeners for {@see ConfiguredPasswordOutdated} register on $eventDispatcher; a
     * null one disables the dispatch.
     */
    public function __construct(
        private RdsIamTokenProvider $tokens,
        private RdsSecretPasswordProvider $passwords,
        private ?string $iamUsername,
        private ?string $secretArn,
        private ?CacheItemPoolInterface $cache = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new RdsAuthDriver($driver, $this->tokens, $this->passwords, $this->iamUsername, $this->secretArn, $this->cache, DatabaseEngine::fromDriver($driver), $this->eventDispatcher);
    }
}
