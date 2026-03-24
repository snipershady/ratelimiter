<?php

namespace RateLimiter\Adapter;

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
 * Abstracts the differences between Predis and the php-redis extension,
 * exposing only the operations required by the rate-limiter business logic.
 *
 * Implementations must guarantee that increment() is atomic (Redis INCR
 * is natively atomic, so no MULTI/EXEC is required for that operation alone).
 * expireAndGet() wraps EXPIRE + GET in a single transaction so the TTL is
 * always set before the value is read back.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
interface RedisAdapterInterface
{
    /**
     * Atomically increment the counter for $key and return the new value.
     * Redis INCR is atomic by design; no MULTI/EXEC wrapper is needed.
     */
    public function increment(string $key): int;

    /**
     * Set the TTL on $key and return its current value in a single transaction.
     * Called only on the first request of a window (counter == 1) to bind the
     * expiry to the key before any concurrent reader can observe it without a TTL.
     */
    public function expireAndGet(string $key, int $ttl): int;

    /**
     * Return the current integer value of $key, or 0 if the key does not exist.
     */
    public function get(string $key): int;

    /**
     * Set the TTL on $key without reading its value.
     * Used to assign the observation window to the violation counter.
     */
    public function expire(string $key, int $ttl): void;

    /**
     * Delete $key and return the number of keys actually removed (0 or 1).
     */
    public function del(string $key): int;
}
