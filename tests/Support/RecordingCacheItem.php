<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Psr\Cache\CacheItemInterface;

final class RecordingCacheItem implements CacheItemInterface
{
    public int|\DateInterval|null $expiresAfter = null;

    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private readonly bool $hit = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        $this->expiresAfter = $time;

        return $this;
    }
}
