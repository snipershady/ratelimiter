<?php

declare(strict_types=1);

namespace RateLimiter\Service;

/*
 * Copyright (C) 2022 Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Memcached-backed sliding-window-counter rate limiter (see
 * AbstractSlidingWindowCounterService for the algorithm). Only supplies the
 * four small backend primitives the abstract base needs; the bucket math,
 * ban-violation handling, and clear logic all live there.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceMemcachedSlidingWindow extends AbstractSlidingWindowCounterService
{
    use MemcachedAtomicCounterTrait;

    public function __construct(private readonly \Memcached $client)
    {
    }

    #[\Override]
    protected function incrementBucket(string $bucketKey, int $ttl): int
    {
        return $this->atomicIncrement($bucketKey, $ttl);
    }

    #[\Override]
    protected function getBucketCount(string $bucketKey): int
    {
        return (int) $this->client->get($bucketKey);
    }

    #[\Override]
    protected function setKey(string $key, int $value, int $ttl): void
    {
        $this->client->set($key, $value, $this->normalizeTtl($ttl));
    }

    #[\Override]
    protected function deleteKey(string $key): bool
    {
        return $this->client->delete($key);
    }
}
