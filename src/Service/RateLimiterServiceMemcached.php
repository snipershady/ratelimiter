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
    use MemcachedAtomicCounterTrait;

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
    protected function getViolationCount(string $violationCountKey): int
    {
        return (int) $this->client->get($violationCountKey);
    }

    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): int
    {
        // TTL = $banTimeFrame: the violation counter expires $banTimeFrame seconds after
        // the FIRST violation. atomicIncrement() only sets TTL at key creation (fixed window).
        return $this->atomicIncrement($violationCountKey, $banTimeFrame);
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return $this->client->delete($key);
    }

    #[\Override]
    public function clearBan(string $key, ?string $clientIp = null): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        $mainCleared = $this->client->delete($key);
        $violationCleared = $this->client->delete($violationCountKey);

        return $mainCleared || $violationCleared;
    }
}
