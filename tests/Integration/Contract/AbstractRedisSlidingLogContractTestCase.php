<?php

declare(strict_types=1);

namespace RateLimiter\Tests\Integration\Contract;

use RateLimiter\Enum\AlgorithmEnum;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;

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
 * Shared contract for the two Redis backends with AlgorithmEnum::SLIDING_WINDOW
 * (RateLimiterServiceRedisSlidingWindow) — an exact sliding-window log, not
 * the two-bucket approximation used by Memcached/APCu (see
 * AbstractSlidingWindowCounterContractTestCase for that one). Ban-lifecycle
 * behaviour is identical to RateLimiterServiceRedis (same RedisBanTrait), and
 * the sliding log's own EXPIRE is refreshed unconditionally on every call
 * (see SlidingLogAdapterInterface::recordAndCount()), so ttl() introspection
 * is just as reliable here as for the fixed-window Redis contract.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractRedisSlidingLogContractTestCase extends AbstractRateLimiterContractTestCase
{
    protected object $redis;

    abstract protected function connect(): object;

    abstract protected function cacheEnum(): CacheEnum;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = $this->connect();
        $this->redis->flushall();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushall();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory($this->cacheEnum(), $this->redis, AlgorithmEnum::SLIDING_WINDOW);
    }

    /**
     * Contrast with AbstractSlidingWindowCounterContractTestCase's
     * testBoundaryIsSmoothedNotHardReset(): the exact log has no
     * approximation to smooth — once every timestamp from the previous
     * period has fallen outside the trailing $ttl-second window, it stops
     * counting immediately, with no residual weight at all.
     */
    public function testBoundaryHasNoResidualWeight(): void
    {
        $limit = 3;
        $ttl = 3;
        $key = $this->uniqueKey();
        $limiter = $this->makeLimiter();

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        sleep($ttl + 1); // every previous timestamp is now outside the window

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // fully free, no smoothing
    }

    public function testLimitOneAgainTtlExpire(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 20;
        $sleep = 2;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep($sleep);
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl, $this->redis->ttl($key)); // EXPIRE is refreshed on every call, unlike the fixed window
    }

    // -------------------------------------------------------------------------
    // isLimitedWithBan() — basic lifecycle (identical to RateLimiterServiceRedis,
    // via the same RedisBanTrait).
    // -------------------------------------------------------------------------

    public function testRateLimitWithBan(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 4;
        $clientIp = null;

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // first request: not limited
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 1
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 2
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3 = maxAttempts

        sleep(3); // let ttl expire to enable the ban ttl
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window opens: first request of new window is free
        $this->assertSame($banTtl, $this->redis->ttl($key));

        sleep($banTtl + 1); // let ban ttl expire
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban expired: back to normal ttl window
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    public function testClearBanActuallyUnbansClient(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('clearban');
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 30;
        $banTtl = 60;
        $clientIp = '203.0.113.9';

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // let the normal window expire

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertSame($banTtl, $this->redis->ttl($key)); // confirms the client is genuinely banned

        $this->assertTrue($limiter->clearBan($key, $clientIp));

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }
}
