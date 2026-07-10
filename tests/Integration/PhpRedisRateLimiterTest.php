<?php

namespace RateLimiter\Tests\Integration;

use RateLimiter\Enum\CacheEnum;
use RateLimiter\Tests\Integration\Contract\AbstractRedisFamilyContractTestCase;

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
 * Integration tests for the PHP_REDIS backend (php-redis native extension).
 * Shared behaviour is inherited from AbstractRedisFamilyContractTestCase /
 * AbstractRateLimiterContractTestCase; only the WRONGTYPE fail-closed tests below
 * are specific to php-redis, since \Redis::incr()/get() return false on a
 * backend error instead of throwing like Predis does for the same condition.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/PhpRedisRateLimiterTest.php
 */
class PhpRedisRateLimiterTest extends AbstractRedisFamilyContractTestCase
{
    #[\Override]
    protected function connect(): object
    {
        $redis = new \Redis();
        $redis->pconnect(
            $this->servername,
            $this->port,
            2,
            'persistent_id_rl_test'
        );

        return $redis;
    }

    #[\Override]
    protected function cacheEnum(): CacheEnum
    {
        return CacheEnum::PHP_REDIS;
    }

    // -------------------------------------------------------------------------
    // Backend failure handling — specific to php-redis
    // -------------------------------------------------------------------------

    /**
     * Regression test for PhpRedisAdapter::increment(): \Redis::incr() returns
     * false (not an exception) on a WRONGTYPE error, instead of throwing like
     * Predis does for the same condition. Before the fix, that false was
     * silently cast to 0 and treated as "first request ever", letting traffic
     * through unlimited (fail-open). A rate limiter is a security control, so
     * a real backend error must now fail closed by throwing instead.
     */
    public function testPhpRedisFailsClosedOnWrongTypeIncrement(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('wrongtype');

        // A list value makes INCR fail with WRONGTYPE instead of a numeric result.
        $this->redis->rPush($key, 'not_a_counter');

        $this->expectException(\RuntimeException::class);
        $limiter->isLimited($key, 5, 60);
    }

    /**
     * Regression test for PhpRedisAdapter::get(): \Redis::get() returns false
     * both for a legitimate cache miss and for a genuine WRONGTYPE error, with
     * no way to tell them apart from the return value alone. Before the fix,
     * getViolationCount() treated a WRONGTYPE error on the violation counter
     * the same as "no violations yet", silently disabling the ban feature for
     * that client instead of failing closed.
     */
    public function testPhpRedisFailsClosedOnWrongTypeViolationCounter(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('wrongtype_ban');
        $violationCountKey = 'BAN_violation_count_' . $key;

        // A list value makes GET fail with WRONGTYPE instead of a numeric result.
        $this->redis->rPush($violationCountKey, 'not_a_counter');

        $this->expectException(\RuntimeException::class);
        $limiter->isLimitedWithBan($key, 5, 60, 3, 30, 120, null);
    }
}
