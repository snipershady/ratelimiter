<?php

declare(strict_types=1);

namespace RateLimiter\Service;

use RateLimiter\Adapter\RedisAdapterInterface;

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
 * Redis-backed rate limiter. Works with both the Predis library and the
 * php-redis native extension via the RedisAdapterInterface abstraction.
 *
 * The correct adapter is injected by AbstractRateLimiterService::factory(),
 * so callers never need to instantiate this class directly.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceRedis extends AbstractRateLimiterService
{
    public function __construct(private readonly RedisAdapterInterface $adapter)
    {
    }

    /**
     * {@inheritDoc}
     *
     * Redis INCR is natively atomic, so no MULTI/EXEC is needed for the
     * increment itself. A transaction is used only for the EXPIRE + GET pair
     * on the first request of each window, ensuring the TTL is bound to the
     * key before any concurrent reader can observe it without an expiry.
     */
    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        $count = $this->adapter->increment($key);

        if ($count <= 1) {
            $count = $this->adapter->expireAndGet($key, $ttl);
        }

        return $count > $limit;
    }

    #[\Override]
    protected function getViolationCount(string $violationCountKey): int
    {
        return $this->adapter->get($violationCountKey);
    }

    /**
     * The violation counter uses a fixed observation window ($banTimeFrame).
     * Its TTL is set only on the first violation and never renewed, so the
     * window starts at the first offence and expires $banTimeFrame seconds
     * later regardless of subsequent activity.
     */
    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): int
    {
        $count = $this->adapter->increment($violationCountKey);

        // expire() uses EXPIRE ... NX, so this is safe to call on every
        // violation: it binds the window on the first one and is a no-op
        // afterwards, without depending on a non-atomic "increment, then
        // conditionally expire" step that could leave the counter
        // permanently without a TTL if a crash landed between the two.
        $this->adapter->expire($violationCountKey, $banTimeFrame);

        return $count;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return (bool) $this->adapter->del($key);
    }

    #[\Override]
    public function clearBan(string $key, ?string $clientIp = null): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        $mainCleared = $this->adapter->del($key) > 0;
        $violationCleared = $this->adapter->del($violationCountKey) > 0;

        return $mainCleared || $violationCleared;
    }
}
