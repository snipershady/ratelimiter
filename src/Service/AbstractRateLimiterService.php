<?php

namespace RateLimiter\Service;

use Predis\Client;
use RateLimiter\Adapter\PhpRedisAdapter;
use RateLimiter\Adapter\PredisAdapter;
use RateLimiter\Enum\CacheEnum;

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
 * Base class for all rate limiter backends. Provides shared input-validation
 * helpers and the backward-compatible factory() entry point.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractRateLimiterService implements RateLimiterInterface
{
    /**
     * @param string $key   <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int    $limit <p>Limit</p>
     * @param int    $ttl   <p>Timeframe</p>
     */
    #[\Override]
    abstract public function isLimited(string $key, int $limit, int $ttl): bool;

    /**
     * <p>Context: It is necessary to limit access to a specific function, but you are receiving more requests than you'd want to accept from the same client, so you want to punish the client more than the other.
     *
     * </p>
     *
     * @param string      $key          <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int         $limit        <p>Limit</p>
     * @param int         $ttl          <p>Timeframe</p>
     * @param int         $maxAttempts  <p>Maximum attempts before a client will receive a ban</p>
     * @param int         $banTimeFrame <p>Timeframe during a client cannot be limited more than the max attempts number</p>
     * @param int         $banTtl       <p>New timeframe for banished client</p>
     * @param string|null $clientIp     <p>Useful to ban a specific client from a function</p>
     */
    #[\Override]
    abstract public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool;

    /**
     * <p>Delete the limited key</p>.
     *
     * @param string $key <p>key to set free from limiter</p>
     */
    #[\Override]
    abstract public function clearRateLimitedKey(string $key): bool;

    /**
     * <p>Verify if <b>ttl</b> parameter is positive integer. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkTTL(int $ttl): void
    {
        if (!$this->isPositiveInteger($ttl)) {
            throw new \InvalidArgumentException(sprintf('TTL must be a positive integer, %d given', $ttl));
        }
    }

    /**
     * <p>Verify if <b>$timeFrame</b> parameter is positive integer. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkTimeFrame(int $timeFrame): void
    {
        if (!$this->isPositiveInteger($timeFrame)) {
            throw new \InvalidArgumentException(sprintf('TimeFrame must be a positive integer, %d given', $timeFrame));
        }
    }

    /**
     * <p>Verify if <b>key</b> parameter is not empty. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkKey(string $key): void
    {
        if (empty($key)) {
            throw new \InvalidArgumentException(sprintf('Key cannot be empty, %s given, instead', $key));
        }
    }

    public static function factory(CacheEnum $cacheEnum, Client|\Redis|\Memcached|null $client = null): AbstractRateLimiterService
    {
        return match ($cacheEnum) {
            CacheEnum::APCU => new RateLimiterServiceAPCu(),
            CacheEnum::REDIS => new RateLimiterServiceRedis(new PredisAdapter($client)),
            CacheEnum::PHP_REDIS => new RateLimiterServiceRedis(new PhpRedisAdapter($client)),
            CacheEnum::MEMCACHED => new RateLimiterServiceMemcached($client),
        };
    }

    private function isPositiveInteger(int $value): bool
    {
        return $value > 0;
    }
}
