<?php

declare(strict_types=1);

namespace RateLimiter\Tests\Integration;

use RateLimiter\Enum\CacheEnum;
use RateLimiter\Tests\Integration\Contract\AbstractRedisSlidingLogContractTestCase;

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
 * Integration tests for the PHP_REDIS backend (php-redis native extension)
 * with AlgorithmEnum::SLIDING_WINDOW (RateLimiterServiceRedisSlidingWindow).
 * Backend wiring only — every test is inherited from
 * AbstractRedisSlidingLogContractTestCase / AbstractRateLimiterContractTestCase.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/PhpRedisSlidingWindowRateLimiterTest.php
 */
class PhpRedisSlidingWindowRateLimiterTest extends AbstractRedisSlidingLogContractTestCase
{
    #[\Override]
    protected function connect(): object
    {
        $redis = new \Redis();
        $redis->pconnect(
            $this->servername,
            $this->port,
            2,
            'persistent_id_rl_test_sliding'
        );

        return $redis;
    }

    #[\Override]
    protected function cacheEnum(): CacheEnum
    {
        return CacheEnum::PHP_REDIS;
    }
}
