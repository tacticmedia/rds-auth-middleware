<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Doctrine\DBAL\Driver\Connection;

/**
 * Inert connection; the tests only compare instances. One signature set satisfies
 * the Connection interface of DBAL 3 and DBAL 4: never narrows every return type,
 * mixed widens every parameter, and the DBAL 3 extras ($type, $name) stay optional.
 */
final class FakeConnection implements Connection
{
    public function prepare(string $sql): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function query(string $sql): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function quote(mixed $value, mixed $type = null): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function exec(string $sql): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function lastInsertId(mixed $name = null): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function beginTransaction(): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function commit(): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function rollBack(): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getNativeConnection(): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function getServerVersion(): never
    {
        throw new \LogicException('Not used by these tests.');
    }
}
