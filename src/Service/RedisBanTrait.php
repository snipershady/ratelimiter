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
 * Ban-violation-counter logic shared by every Redis-backed rate limiter,
 * regardless of algorithm: the ban observation window is always fixed-window
 * by design (see the class-level note on
 * AbstractRateLimiterService::isLimitedWithBan()), so RateLimiterServiceRedis
 * (fixed window) and RateLimiterServiceRedisSlidingWindow (sliding log)
 * implement it identically via the same RedisAdapterInterface primitives.
 * Extracted here so a fix to one automatically applies to the other instead
 * of risking silent drift between two hand-duplicated copies.
 *
 * Requires the using class to expose a `RedisAdapterInterface $adapter`
 * property, as both classes above already do via constructor promotion.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
trait RedisBanTrait
{
    #[\Override]
    protected function getViolationCount(string $violationCountKey): int
    {
        return $this->adapter->get($violationCountKey);
    }

    /**
     * {@inheritDoc}
     *
     * expire() uses EXPIRE ... NX, so this is safe to call on every
     * violation: it binds the window on the first one and is a no-op
     * afterwards, without depending on a non-atomic "increment, then
     * conditionally expire" step that could leave the counter permanently
     * without a TTL if a crash landed between the two.
     */
    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): int
    {
        $count = $this->adapter->increment($violationCountKey);
        $this->adapter->expire($violationCountKey, $banTimeFrame);

        return $count;
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
