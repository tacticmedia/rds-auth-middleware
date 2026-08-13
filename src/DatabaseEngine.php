<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;

/**
 * AbstractMySQLDriver also covers MariaDB. Unknown drivers get the PostgreSQL
 * behaviour.
 */
enum DatabaseEngine
{
    case Postgres;
    case Mysql;

    public static function fromDriver(Driver $driver): self
    {
        return $driver instanceof AbstractMySQLDriver ? self::Mysql : self::Postgres;
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Postgres => 5432,
            self::Mysql => 3306,
        };
    }
}
