<?php

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
 * Memcached-backed rate limiter using the php-memcached native extension.
 *
 * TTL semantics: Memcached's increment() never resets a key's expiry, so the
 * window is always fixed from the moment the key is first created. This gives
 * the same fixed-window behaviour as the APCu backend.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceMemcached extends AbstractRateLimiterService
{
    public function __construct(private readonly \Memcached $client)
    {
    }

    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        return $this->atomicIncrement($key, $ttl) > $limit;
    }

    #[\Override]
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
    {
        $this->checkTTL($banTtl);
        $this->checkTTL($ttl);
        $this->checkTimeFrame($banTimeFrame);

        $violationCountKey = null !== $clientIp
            ? 'BAN_violation_count_'.$key.'_'.$clientIp
            : 'BAN_violation_count_'.$key;

        if ((int) $this->client->get($violationCountKey) >= $maxAttempts) {
            $ttl = $banTtl;
        }

        $actual = $this->isLimited($key, $limit, $ttl);

        if ($actual) {
            // TTL = $banTimeFrame: the violation counter expires $banTimeFrame seconds after
            // the FIRST violation. atomicIncrement() only sets TTL at key creation (fixed window).
            $this->atomicIncrement($violationCountKey, $banTimeFrame);
        }

        return $actual;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return $this->client->delete($key);
    }

    /**
     * Atomically increments a counter, creating it with the given TTL if it does not exist.
     *
     * Sequence (compatible with both ASCII and binary Memcached protocol):
     *   1. increment() — succeeds if the key already exists.
     *   2. On miss, add() with value 1 and the desired TTL — atomically creates the key
     *      if no concurrent writer has done so between step 1 and now.
     *   3. If add() loses the race (another process created the key first), retry increment().
     *
     * increment() never resets the key TTL, so the expiry window is fixed from creation.
     */
    private function atomicIncrement(string $key, int $ttl): int
    {
        $count = $this->client->increment($key);

        if (false !== $count) {
            return (int) $count;
        }

        // Key does not exist: create it atomically with value 1.
        if ($this->client->add($key, 1, $ttl)) {
            return 1;
        }

        // Another process created the key between our increment() miss and add(); retry.
        $count = $this->client->increment($key);

        return false !== $count ? (int) $count : 1;
    }
}
