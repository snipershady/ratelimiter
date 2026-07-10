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
 * Atomic increment-with-TTL-on-create primitive shared by every APCu-backed
 * rate limiter, regardless of algorithm. Extracted so
 * RateLimiterServiceAPCu (fixed window) and RateLimiterServiceAPCuSlidingWindow
 * (sliding window) share the identical implementation instead of a
 * hand-duplicated copy.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
trait ApcuAtomicCounterTrait
{
    /**
     * Atomically increments a counter via apcu_inc(), which is itself a single
     * lock-free atomic operation: it creates the key with the given TTL when
     * missing, or increments the existing value otherwise, in one call. A
     * separate exists-check-then-CAS-loop is unnecessary and, worse, races
     * against key expiry (a key that expires between the check and the CAS
     * can never satisfy apcu_cas(), spinning forever).
     */
    private function getActual(string $key, int $step, int $ttl): int
    {
        $success = null;

        return (int) apcu_inc($key, $step, $success, $ttl);
    }
}
