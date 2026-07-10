<?php

declare(strict_types=1);

namespace RateLimiter\Service;

use RateLimiter\Adapter\RedisAdapterInterface;
use RateLimiter\Adapter\SlidingLogAdapterInterface;

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
 * Redis-backed sliding-window-log rate limiter: every request's timestamp is
 * recorded in a per-key sorted set (ZSET), timestamps older than $ttl seconds
 * are evicted on every call, and the remaining cardinality is the exact
 * count of requests within the trailing $ttl-second window. Unlike the fixed
 * window, there is no boundary-burst effect (up to 2x $limit requests
 * passing across a single window boundary) — at the cost of one ZSET entry
 * per request instead of a single integer.
 *
 * Works with both the Predis library and the php-redis native extension via
 * the RedisAdapterInterface (ban counter) and SlidingLogAdapterInterface
 * (sliding log) abstractions — PredisAdapter and PhpRedisAdapter both
 * implement both. The correct adapter is injected by
 * AbstractRateLimiterService::factory(), so callers never need to
 * instantiate this class directly.
 *
 * The ban-violation counter (isLimitedWithBan()'s getViolationCount() /
 * recordViolation()) intentionally stays fixed-window, identical to
 * RateLimiterServiceRedis — see RedisBanTrait.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceRedisSlidingWindow extends AbstractRateLimiterService
{
    use RedisBanTrait;

    public function __construct(private readonly RedisAdapterInterface&SlidingLogAdapterInterface $adapter)
    {
    }

    /**
     * {@inheritDoc}
     *
     * $now/$cutoff are computed in milliseconds so that two requests within
     * the same second are still ordered/evicted correctly; $ttl itself
     * remains a plain "seconds" argument, as required by the contract shared
     * with every other backend/algorithm. $member is unique per call (not
     * just $now) so two requests scored in the same millisecond don't
     * collide into a single ZSET entry and undercount.
     */
    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        $now = microtime(true) * 1000;
        $cutoff = $now - ($ttl * 1000);
        $member = sprintf('%.6F:%s', $now, bin2hex(random_bytes(8)));

        $count = $this->adapter->recordAndCount($key, $now, $member, $cutoff, $ttl);

        return $count > $limit;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return (bool) $this->adapter->del($key);
    }
}
