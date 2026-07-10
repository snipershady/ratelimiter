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
    use RedisBanTrait;

    public function __construct(private readonly RedisAdapterInterface $adapter)
    {
    }

    /**
     * {@inheritDoc}
     *
     * Redis INCR is natively atomic, so no MULTI/EXEC is needed for the
     * increment itself. A transaction is used only for the EXPIRE + GET pair
     * on the first request of each window, ensuring the TTL is bound to the
     * key before any concurrent reader can observe it without an expiry, and
     * picking up any concurrent increments that raced in during that window.
     *
     * Every other request in the window additionally calls expire() with
     * EXPIRE ... NX, mirroring recordViolation()'s self-healing pattern: it
     * is a no-op once the TTL is already bound, but heals a key left
     * permanently without one if the "first request" above ever crashed
     * between its INCR and expireAndGet() call — otherwise that key would
     * stay stuck above $limit forever, since INCR alone never sets a TTL.
     */
    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        $count = $this->adapter->increment($key);

        if ($count <= 1) {
            $count = $this->adapter->expireAndGet($key, $ttl);
        } else {
            $this->adapter->expire($key, $ttl);
        }

        return $count > $limit;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return (bool) $this->adapter->del($key);
    }
}
