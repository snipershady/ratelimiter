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
 * Description of RatelimiterService
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceRedis extends AbstractRateLimiterService {

    private Client $redis;

    public function __construct(Client $redis) {
        $this->redis = $redis;
    }

    /**
     * {@inheritDoc}
     * <p>The strategy with the Redis instance is more secure than APCu, because of the transaction that grants all-or-nothing execution</p>
     */
    public function isLimited(string $key, int $limit, int $ttl): bool {
        $this->checkKey($key);
        $this->checkTTL($ttl);
        $actual = (int)($this->redis->transaction()->incr($key)->get($key)->execute())[0];
        if($actual === 1){
           $actual = (int) ($this->redis->transaction()->expire($key, $ttl)->get($key)->execute())[0];
        }
        
        if($actual > $limit){
            return true;
        }
                
        return false;
    }

    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool {
        // vorrei fare che se si supera maxAttempts allora il nuovo TTL sarà più grande di qualcosa passato in input o un moltiplicatore...
        
        $violationCountKey = "BAN_violation_count".$key . $clientIp;
        $needBan = (int) $this->redis->get($violationCountKey);
        if($needBan >= $maxAttempts){
            $ttl = $banTtl;
        }
        $actual = $this->isLimited($key, $limit, $ttl);
        
        if($actual){
            $check = (int)($this->redis->transaction()->incr($violationCountKey)->expire($violationCountKey, $banTimeFrame)->get($violationCountKey)->execute())[0];
        }
        
        return $actual > $limit;
    }

}
