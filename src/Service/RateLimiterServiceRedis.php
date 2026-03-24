<?php

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

    /**
     * {@inheritDoc}
     *
     * The violation counter uses a fixed observation window ($banTimeFrame).
     * Its TTL is set only on the first violation and never renewed, so the
     * window starts at the first offence and expires $banTimeFrame seconds
     * later regardless of subsequent activity.
     */
    #[\Override]
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
    {
        $this->checkTTL($banTtl);
        $this->checkTTL($ttl);
        $this->checkTimeFrame($banTimeFrame);

        $violationCountKey = null !== $clientIp
            ? 'BAN_violation_count_'.$key.'_'.$clientIp
            : 'BAN_violation_count_'.$key;

        if ($this->adapter->get($violationCountKey) >= $maxAttempts) {
            $ttl = $banTtl;
        }

        $actual = $this->isLimited($key, $limit, $ttl);

        if ($actual) {
            $violationCount = $this->adapter->increment($violationCountKey);

            if ($violationCount <= 1) {
                $this->adapter->expire($violationCountKey, $banTimeFrame);
            }
        }

        return $actual;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return (bool) $this->adapter->del($key);
    }
}
