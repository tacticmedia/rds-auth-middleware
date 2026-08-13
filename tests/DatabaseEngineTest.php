<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use Doctrine\DBAL\Driver\PDO\MySQL\Driver as PdoMySQLDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PdoPgSQLDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TacticMedia\RdsAuth\DatabaseEngine;
use TacticMedia\RdsAuth\Tests\Support\FakeDriver;

/**
 * @internal
 */
#[CoversClass(DatabaseEngine::class)]
final class DatabaseEngineTest extends TestCase
{
    #[TestDox('Detects MySQL from the wrapped driver')]
    public function testDetectsMysql(): void
    {
        self::assertSame(DatabaseEngine::Mysql, DatabaseEngine::fromDriver(new PdoMySQLDriver()));
        self::assertSame(3306, DatabaseEngine::Mysql->defaultPort());
    }

    #[TestDox('Detects PostgreSQL from the wrapped driver')]
    public function testDetectsPostgres(): void
    {
        self::assertSame(DatabaseEngine::Postgres, DatabaseEngine::fromDriver(new PdoPgSQLDriver()));
        self::assertSame(5432, DatabaseEngine::Postgres->defaultPort());
    }

    #[TestDox('Unknown drivers fall back to the PostgreSQL behaviour')]
    public function testUnknownDriverFallsBackToPostgres(): void
    {
        self::assertSame(DatabaseEngine::Postgres, DatabaseEngine::fromDriver(new FakeDriver()));
    }
}
