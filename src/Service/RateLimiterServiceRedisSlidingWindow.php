<?php

namespace RateLimiter\Service;

use Predis\Client;

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
 * Description of RateLimiterServiceRedisSlidingWindow
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceRedisSlidingWindow extends AbstractRateLimiterService {

    private Client $redis;

    public function __construct(Client $redis) {
        $this->redis = $redis;
    }

    /**
     * {@inheritDoc}
     * <p>The strategy with the Redis instance is more secure than APCu, because of the transaction that grants all-or-nothing execution</p>
     */
    public function isLimited(string $key, int $limit, int $ttl): bool {

        $now = microtime(true) * 1000; // Timestamp in millisecondi
        // begin transaction
        $transaction = $this->redis->transaction();

        // Add current timestamp to the set identified by the key
        $transaction->zadd($key, $now, $now);

        // Remove all old timestamps, throwing them through the window
        $transaction->zremrangebyscore($key, '-inf', $now - $ttl);

        // check if limit is reached (this is the 3rd command in transaction, so position [2] of the array result
        $transaction->zcard($key);

        // Exec transaction
        $result = $transaction->execute();

        $count = (int) $result[2];

        return !empty($result) && $count > $limit;
    }

    /**
     * {@inheritDoc}
     * <p>The strategy with the Redis instance is more secure than APCu, because of the transaction that grants all-or-nothing execution</p>
     */
    public function previsLimited(string $key, int $limit, int $ttl): bool {
        $this->checkKey($key);
        $this->checkTTL($ttl);
        $actualArray = ($this->redis->transaction()->incr($key)->get($key)->execute());
        $actual = is_array($actualArray) && array_key_exists(0, $actualArray) ? (int) $actualArray[0] : 0;
        if ($actual <= 1) {
            $actual = (int) ($this->redis->transaction()->expire($key, $ttl)->get($key)->execute())[0];
        }

        return($actual > $limit);
    }

    /**
     * {@inheritDoc}
     * <p>The strategy with the Redis instance is more secure than APCu, because of the transaction that grants all-or-nothing execution</p>
     */
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool {
        $this->checkTTL($banTtl);
        $this->checkTimeFrame($banTimeFrame);

        $violationCountKey = "BAN_violation_count" . $key . $clientIp;
        $needBan = (int) $this->redis->get($violationCountKey);
        if ($needBan >= $maxAttempts) {
            $ttl = $banTtl;
        }
        $actual = $this->isLimited($key, $limit, $ttl);

        if ($actual) {
            $this->redis->transaction()->incr($violationCountKey)->expire($violationCountKey, $banTtl)->get($violationCountKey)->execute();
        }

        return $actual;
    }

    /**
     * {@inheritDoc}
     */
    public function clearRateLimitedKey(string $key): bool {
        $this->checkKey($key);
        return (bool) $this->redis->del($key);
    }
}
