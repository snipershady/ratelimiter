<?php

namespace RateLimiter\Tests;

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
 * Ban functionality tests for the PHP Redis (native extension) backend.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimitPhpRedisBanTest.php
 */
class RateLimitPhpRedisBanTest extends AbstractTestCase
{
    private \Redis $redis;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new \Redis();
        $this->redis->pconnect(
            $this->servername,
            $this->port,
            2,
            'persistent_id_rl_ban_test'
        );
        $this->redis->flushall();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushall();
    }

    // -------------------------------------------------------------------------
    // Basic ban lifecycle
    // -------------------------------------------------------------------------

    /**
     * After reaching maxAttempts violations, the next access window uses banTtl.
     * Once banTtl expires, the client returns to the normal ttl window.
     */
    public function testRateLimitWithBanPhpRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 4;
        $clientIp = null;

        // 4 requests: 1 not limited, 3 limited --> violation_count = 3
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // first request: not limited
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 1
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 2
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 3 = maxAttempts
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep(3); // let normal TTL expire to start a new window

        // violation_count = 3 >= maxAttempts --> banTtl applied on new window key
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban window opens: first request of new window is free
        $this->assertSame($banTtl, $this->redis->ttl($key));

        sleep($banTtl + 1); // let ban expire

        // After ban expires client returns to normal behavior (violation_count also expired)
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    /**
     * Detailed step-by-step verification of the ban state transitions.
     */
    public function testRateLimitWithBanPhpRedisDetailed(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 5;
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 1
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 2
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 3

        sleep($ttl + 1); // normal TTL expires

        // violation_count = 3 >= maxAttempts --> next window uses banTtl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // first request of new (banned) window
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertSame($banTtl, $this->redis->ttl($key));

        sleep($ttl + 1); // wait inside the ban window

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result); // still within ban window

        sleep(3); // let ban window fully expire

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban window expired, fresh start
    }

    // -------------------------------------------------------------------------
    // $banTimeFrame expiration
    // -------------------------------------------------------------------------

    /**
     * After $banTimeFrame seconds the violation counter expires and the client is
     * no longer considered for banning. The next key must be created with $ttl
     * (not $banTtl), confirming the reset.
     *
     * Timeline (banTimeFrame=6, ttl=2):
     *   t=0  req1: NOT limited.  req2: LIMITED --> violation_count=1, TTL=6s
     *   t=3  sleep(ttl+1): main key expired; violation_count has ~3s left
     *   t=3  req3: NOT limited.  req4: LIMITED --> violation_count=2 (≥ maxAttempts=2)
     *   t=9  sleep(banTimeFrame): violation_count expired at t≈6; main key also expired
     *   t=9  req5: violation_count=0 --> NOT limited; key created with ttl (not banTtl) ✓
     */
    public function testBanTimeFrameExpirationResetsViolationsPhpRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test_btf_'.microtime(true);
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 6;
        $banTtl = 60; // intentionally very long — must NOT be applied after reset
        $clientIp = null;

        // Accumulate violation_count = 1 (created with TTL = banTimeFrame)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep($ttl + 1); // t≈3s: main key expired; violation_count still alive (~3s left)

        // Bring violation_count to maxAttempts without triggering a ban (ban is checked at the START of the call)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // NOT limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2

        // violation_count = 2 >= maxAttempts. Ban will apply on the NEXT call — but we sleep first.
        // violation_count was created at t≈0 with TTL=6s --> expires at t≈6s
        sleep($banTimeFrame); // t≈9s: violation_count expired; main key also expired

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);                           // violation_count reset --> NOT banned
        $this->assertSame($ttl, $this->redis->ttl($key));     // key created with normal $ttl, not $banTtl
    }

    // -------------------------------------------------------------------------
    // Client IP isolation
    // -------------------------------------------------------------------------

    /**
     * Violation counters are per-clientIp. Client A reaching the ban threshold
     * must not affect Client B whose violation counter is independent.
     *
     * Strategy: use clearRateLimitedKey() to reset the shared main key between
     * cycles so each IP scenario can be observed cleanly.
     */
    public function testClientIpIsolationPhpRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test_ip_iso_'.microtime(true);
        $limit = 1;
        $ttl = 60;       // long enough to avoid expiry during the test
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 120;
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // --- Bring client A to ban threshold (violation_A = 2 >= maxAttempts) ---

        // Cycle 1 for A: 1 not limited, 1 limited --> violation_A = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // Cycle 2 for A: 1 not limited, 1 limited --> violation_A = 2
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // Verify A is banned: violation_A=2 >= maxAttempts --> key created with banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertSame($banTtl, $this->redis->ttl($key));
        $limiter->clearRateLimitedKey($key);

        // --- Verify client B is NOT banned (violation_B = 1 < maxAttempts) ---

        // Only 1 cycle for B --> violation_B = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // violation_B=1 < 2: key must be created with normal $ttl, not $banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    // -------------------------------------------------------------------------
    // clearRateLimitedKey() edge case
    // -------------------------------------------------------------------------

    public function testClearNonExistentKeyReturnsFalse(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $result = $limiter->clearRateLimitedKey('non_existent_key_'.microtime(true));
        $this->assertFalse($result);
    }
}
