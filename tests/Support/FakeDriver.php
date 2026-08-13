<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Records every connect() attempt and applies one preset outcome per attempt: a
 * DriverException is thrown; null returns the shared FakeConnection.
 */
final class FakeDriver implements Driver
{
    /** @var list<array<string, mixed>> */
    public array $attempts = [];

    public readonly FakeConnection $connection;

    /** @var list<DriverException|null> */
    private array $outcomes;

    public function __construct(?DriverException ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
        $this->connection = new FakeConnection();
    }

    /** @param array<string, mixed> $params */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        $this->attempts[] = $params;

        if ([] === $this->outcomes) {
            throw new \LogicException('connect() called more often than scripted.');
        }

        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof DriverException) {
            throw $outcome;
        }

        return $this->connection;
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        throw new \LogicException('Not used by these tests.');
    }
}
