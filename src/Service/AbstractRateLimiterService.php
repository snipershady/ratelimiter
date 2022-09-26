<?php

namespace RateLimiter\Service;

use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use InvalidArgumentException;

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
abstract class AbstractRateLimiterService {

    private function __construct() {
        
    }

    /**
     * 
     * @param string $key <p>Name of the function you want to limit</p>
     * @param int $limit <p>Limit</p>
     * @param int $ttl <p>Timeframe</p>
     * @return bool
     */
    public abstract function isLimited(string $key, int $limit, int $ttl): bool;

    /**
     * <p>Verify if <b>ttl</b> parameter is positive integer. Throws InvalidArgumentException</p>
     * @param int $ttl
     * @throws InvalidArgumentException
     */
    protected function checkTTL(int $ttl) {
        if ($ttl < 1) {
            throw new InvalidArgumentException("TTL must be positive integer $ttl given, instead");
        }
    }

    /**
     * <p>Verify if <b>key</b> parameter is not empty. Throws InvalidArgumentException</p>
     * @param string $key
     * @throws InvalidArgumentException
     */
    protected function checkKey(string $key) {
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty, $key given, instead");
        }
    }

    /**
     * <p>Verify if <b>step</b> parameter is positive integer. Throws InvalidArgumentException</p>
     * @param int $step
     * @throws InvalidArgumentException
     */
    protected function checkStep(string $step) {
        if ($step < 1) {
            throw new InvalidArgumentException("STEP must be positive integer $step given, instead");
        }
    }

    /**
     * 
     * @param CacheEnum $cacheEnum
     * @param Client $pRedisClient
     * @return AbstractRateLimiterService
     */
    public static function factory(CacheEnum $cacheEnum, Client $pRedisClient = null): AbstractRateLimiterService {
        switch ($cacheEnum) {
            case CacheEnum::APCU:
                return new RateLimiterServiceAPCu();
            default:
            case CacheEnum::REDIS;
                return new RateLimiterServiceRedis($pRedisClient);
        }
    }

}
