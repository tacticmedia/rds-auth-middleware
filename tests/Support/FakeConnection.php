<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Inert connection; the tests only compare instances. */
final class FakeConnection implements Connection
{
    public function prepare(string $sql): Statement
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function query(string $sql): Result
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function quote(string $value): string
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function exec(string $sql): int|string
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function lastInsertId(): int|string
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function beginTransaction(): void
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function commit(): void
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function rollBack(): void
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getNativeConnection(): mixed
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getServerVersion(): string
    {
        throw new \LogicException('Not used by these tests.');
    }
}
