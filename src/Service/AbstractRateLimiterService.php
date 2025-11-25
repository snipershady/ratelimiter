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
abstract class AbstractRateLimiterService
{
    private function __construct()
    {

    }

    /**
     *
     * @param string $key <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int $limit <p>Limit</p>
     * @param int $ttl <p>Timeframe</p>
     */
    abstract public function isLimited(string $key, int $limit, int $ttl): bool;

    /**
     * <p>Context: It is necessary to limit access to a specific function, but you are receiving more requests than you'd want to accept from the same client, so you want to punish the client more than the other
     *
     * </p>
     * @param string $key <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int $limit  <p>Limit</p>
     * @param int $ttl <p>Timeframe</p>
     * @param int $maxAttempts <p>Maximum attempts before a client will receive a ban</p>
     * @param int $banTimeFrame <p>Timeframe during a client cannot be limited more than the max attempts number</p>
     * @param int $banTtl <p>New timeframe for banished client</p>
     * @param string|null $clientIp <p>Useful to ban a specific client from a function</p>
     */
    abstract public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool;

    /**
     * <p>Delete the limited key</p>
     * @param string $key <p>key to set free from limiter</p>
     */
    abstract public function clearRateLimitedKey(string $key): bool;

    /**
     * <p>Verify if <b>ttl</b> parameter is positive integer. Throws InvalidArgumentException</p>
     * @throws InvalidArgumentException
     */
    protected function checkTTL(int $ttl): void
    {
        if (!$this->isPositiveNotZeroInteger($ttl)) {
            throw new InvalidArgumentException("TTL must be positive integer $ttl given, instead");
        }
    }

    /**
     * <p>Verify if <b>$timeFrame</b> parameter is positive integer. Throws InvalidArgumentException</p>
     * @throws InvalidArgumentException
     */
    protected function checkTimeFrame(int $timeFrame): void
    {
        if (!$this->isPositiveNotZeroInteger($timeFrame)) {
            throw new InvalidArgumentException("TimeFrame must be positive integer $timeFrame given, instead");
        }
    }

    /**
     * <p>Verify if <b>key</b> parameter is not empty. Throws InvalidArgumentException</p>
     * @throws InvalidArgumentException
     */
    protected function checkKey(string $key): void
    {
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty, $key given, instead");
        }
    }

    /**
     * <p>Verify if <b>step</b> parameter is positive integer. Throws InvalidArgumentException</p>
     * @throws InvalidArgumentException
     */
    protected function checkStep(int $step): void
    {
        if (!$this->isPositiveNotZeroInteger($step)) {
            throw new InvalidArgumentException("STEP must be positive integer $step given, instead");
        }
    }

    /**
     *
     * @param Client $pRedisClient
     */
    public static function factory(CacheEnum $cacheEnum, ?Client $pRedisClient = null): AbstractRateLimiterService
    {
        switch ($cacheEnum) {
            case CacheEnum::APCU:
                return new RateLimiterServiceAPCu();
            default:
            case CacheEnum::REDIS:
                return new RateLimiterServiceRedis($pRedisClient);
        }
    }

    private function isPositiveNotZeroInteger(int $value): bool
    {
        return $value > 0;
    }
}
