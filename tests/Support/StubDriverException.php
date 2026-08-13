<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;

final class StubDriverException extends \RuntimeException implements DriverException
{
    public function __construct(
        string $message = 'stub failure',
        private readonly ?string $sqlState = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
