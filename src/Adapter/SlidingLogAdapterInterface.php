<?php

declare(strict_types=1);

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
 * Redis primitive needed by the sliding-window-log algorithm: record the
 * current request's timestamp in a per-key sorted set, evict everything
 * older than the window, and return the resulting count — atomically, so
 * concurrent requests never observe a partially-updated set.
 *
 * Kept separate from RedisAdapterInterface (INCR/EXPIRE/GET/DEL, used by the
 * fixed-window algorithm) because it is a different, unrelated primitive:
 * folding it into that interface would force every existing implementer to
 * also support sorted sets it may never use. PredisAdapter and
 * PhpRedisAdapter both implement this interface in addition to
 * RedisAdapterInterface.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
interface SlidingLogAdapterInterface
{
    /**
     * Atomically, in a single transaction:
     *   1. ZADD key $score $member          — record this request
     *   2. ZREMRANGEBYSCORE key -inf ($cutoff — evict entries older than the window
     *   3. ZCARD key                         — count what's left (includes the new member)
     *   4. EXPIRE key $ttl                    — bound the key's lifetime so an inactive
     *                                           key doesn't linger in memory forever
     *      (ZREMRANGEBYSCORE only trims when a new request arrives, so a key with no
     *      further traffic would otherwise never be cleaned up)
     *
     * @param string $member a value unique per call, so two requests scored at the
     *                       same instant don't collide into a single ZSET entry
     *
     * @return int the cardinality after trimming, i.e. the number of requests
     *             still inside the sliding window, including this one
     */
    public function recordAndCount(string $key, float $score, string $member, float $cutoff, int $ttl): int;
}
