<?php

declare(strict_types=1);

namespace RateLimiter\Enum;

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
 * Identifies the rate-limiting algorithm to use when building a rate limiter
 * via the factory. Selecting an algorithm never changes the shape of
 * RateLimiterInterface: it only changes which concrete class factory()
 * returns.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum AlgorithmEnum: int
{
    /**
     * Classic fixed window: a single counter per key, reset when its TTL
     * expires. Simple and cheap, but allows up to 2x $limit requests to pass
     * across a single window boundary (e.g. $limit requests just before
     * expiry, $limit more just after).
     */
    case FIXED_WINDOW = 1;

    /**
     * Sliding window: smooths the fixed-window boundary-burst problem.
     *
     * On Redis (CacheEnum::REDIS / PHP_REDIS) this is an exact sliding-window
     * log: every request's timestamp is recorded in a per-key sorted set,
     * and only requests within the trailing $ttl seconds are ever counted.
     *
     * On Memcached and APCu it is a two-bucket weighted-counter
     * approximation instead: neither backend can maintain an unbounded
     * per-request log atomically without either unbounded memory growth or a
     * CAS loop that can spin forever (see RateLimiterServiceAPCu's
     * class-level note on why that pattern is avoided). The approximation
     * has a small, bounded error and never stores more than two integers per
     * key.
     */
    case SLIDING_WINDOW = 2;
}
