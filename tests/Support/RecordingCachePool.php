<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps every saved item, so a test can read the TTL that the driver passed to
 * expiresAfter(). Implements only the methods the driver calls.
 */
final class RecordingCachePool implements CacheItemPoolInterface
{
    /** @var array<string, RecordingCacheItem> */
    public array $saved = [];

    public function getItem(string $key): CacheItemInterface
    {
        return isset($this->saved[$key])
            ? new RecordingCacheItem($key, $this->saved[$key]->get(), true)
            : new RecordingCacheItem($key);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof RecordingCacheItem) {
            throw new \LogicException('Save only items from this pool.');
        }

        $this->saved[$item->getKey()] = $item;

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->saved[$key]);

        return true;
    }

    public function getItems(array $keys = []): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function hasItem(string $key): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function clear(): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function deleteItems(array $keys): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function saveDeferred(CacheItemInterface $item): never
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function commit(): never
    {
        throw new \LogicException('Not used by these tests.');
    }
}
