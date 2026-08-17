<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;

/**
 * Records every connect() attempt and applies one preset outcome per attempt: a
 * DriverException is thrown; null returns the shared FakeConnection.
 *
 * One signature set satisfies the Driver interface of DBAL 3 and DBAL 4:
 * getSchemaManager() exists only in DBAL 3, $versionProvider only in DBAL 4.
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

    public function getDatabasePlatform(mixed $versionProvider = null): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getSchemaManager(mixed $conn = null, mixed $platform = null): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getExceptionConverter(): never
    {
        throw new \LogicException('Not used by these tests.');
    }
}
