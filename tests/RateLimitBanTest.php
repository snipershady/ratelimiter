<?php

namespace RateLimiter\Tests;

use Predis\Client;
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
 * Integration tests for isLimitedWithBan() across the APCu and Predis backends.
 * Covers ban lifecycle, banTimeFrame expiration, and per-IP violation isolation.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimitBanTest.php
 */
class RateLimitBanTest extends AbstractTestCase
{
    private Client $redis;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Client(sprintf('tcp://%s:%d?persistent=redis01', $this->servername, $this->port));
        $this->redis->flushall();
        apcu_clear_cache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushall();
        apcu_clear_cache();
    }

    public function testRateLimitWithBanRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test'.microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 4;
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // first request: not limited
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 1
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 2
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 3 = maxAttempts
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep(3); // let ttl expire to enable the ban ttl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban window opens: first request of new window is free

        $this->assertSame($banTtl, $this->redis->ttl($key));
        sleep($banTtl + 1); // let expire ban ttl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban expired: back to normal ttl window
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    public function testRateLimitWithBanRedisTwo(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
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
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);

        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertSame($banTtl, $this->redis->ttl($key));
        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        sleep(3);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
    }

    public function testRateLimitWithBanAPCu(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
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
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);

        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);
        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);
    }

    // -------------------------------------------------------------------------
    // $banTimeFrame expiration
    // -------------------------------------------------------------------------

    /**
     * After $banTimeFrame seconds the violation counter expires. The client must
     * no longer be considered for banning and the key must be created with $ttl,
     * not $banTtl.
     *
     * Timeline (banTimeFrame=6, ttl=2):
     *   t=0  req1: NOT limited.  req2: LIMITED --> violation_count=1, TTL=6s
     *   t=3  sleep(ttl+1): main key expired; violation_count has ~3s left
     *   t=3  req3: NOT limited.  req4: LIMITED --> violation_count=2 (≥ maxAttempts=2)
     *   t=9  sleep(banTimeFrame): violation_count expired at t≈6; main key also expired
     *   t=9  req5: violation_count=0 --> NOT banned; key created with $ttl (not $banTtl) ✓
     */
    public function testBanTimeFrameExpirationResetsViolationsRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test_btf_redis_'.microtime(true);
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

        // Bring violation_count to maxAttempts (ban is checked at the START of each call,
        // so these 2 requests read violation_count=1 which is still < maxAttempts=2)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // NOT limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2

        // violation_count=2 >= maxAttempts; expires at t≈6s (banTimeFrame from creation at t≈0)
        sleep($banTimeFrame); // t≈9s: violation_count and main key both expired

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);                        // violations reset --> NOT banned
        $this->assertSame($ttl, $this->redis->ttl($key));  // key uses normal $ttl, not $banTtl
    }

    /**
     * Same scenario as testBanTimeFrameExpirationResetsViolationsRedis but for APCu.
     * apcu_key_info($key)['ttl'] returns the TTL set at key creation, not remaining TTL,
     * so it reliably identifies whether normal $ttl or $banTtl was applied.
     */
    public function testBanTimeFrameExpirationResetsViolationsAPCu(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = 'test_btf_apcu_'.microtime(true);
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 6;
        $banTtl = 60;
        $clientIp = null;

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep($ttl + 1); // t≈3s: main key expired; violation_count still alive

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // NOT limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2

        sleep($banTimeFrame); // t≈9s: violation_count and main key both expired

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $this->assertSame($ttl, apcu_key_info($key)['ttl']); // key created with $ttl, confirming no ban
    }

    // -------------------------------------------------------------------------
    // Client IP isolation
    // -------------------------------------------------------------------------

    /**
     * Each clientIp has an independent violation counter. Client A reaching the
     * ban threshold must not affect Client B whose counter is still below maxAttempts.
     *
     * Verified via Redis TTL: A's key must have $banTtl, B's key must have $ttl.
     */
    public function testClientIpIsolationRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test_ip_iso_redis_'.microtime(true);
        $limit = 1;
        $ttl = 60;       // long enough to avoid expiry during the test
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 120;
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // --- Bring client A to ban threshold (violation_A = 2 >= maxAttempts) ---

        // Cycle 1 for A: 1 not limited + 1 limited --> violation_A = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // Cycle 2 for A: 1 not limited + 1 limited --> violation_A = 2
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // violation_A=2 >= maxAttempts: key must be created with $banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertSame($banTtl, $this->redis->ttl($key));
        $limiter->clearRateLimitedKey($key);

        // --- Verify client B is NOT banned (violation_B = 1 < maxAttempts) ---

        // Only 1 cycle for B --> violation_B = 1 (< maxAttempts=2)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // violation_B=1 < maxAttempts: key must be created with normal $ttl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    /**
     * Same IP isolation test for APCu.
     * apcu_key_info($key)['ttl'] identifies which TTL was applied at key creation.
     */
    public function testClientIpIsolationAPCu(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = 'test_ip_iso_apcu_'.microtime(true);
        $limit = 1;
        $ttl = 60;
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 120;
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // Cycle 1 for A --> violation_A = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // Cycle 2 for A --> violation_A = 2
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // violation_A=2 >= maxAttempts: key must be created with $banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);
        $limiter->clearRateLimitedKey($key);

        // Only 1 cycle for B --> violation_B = 1 (< maxAttempts=2)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // violation_B=1 < maxAttempts: key must be created with normal $ttl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertSame($ttl, apcu_key_info($key)['ttl']);
    }

    // -------------------------------------------------------------------------
    // clearRateLimitedKey() edge case
    // -------------------------------------------------------------------------

    public function testClearNonExistentKeyReturnsFalseRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $result = $limiter->clearRateLimitedKey('non_existent_key_'.microtime(true));
        $this->assertFalse($result);
    }

    public function testClearNonExistentKeyReturnsFalseAPCu(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $result = $limiter->clearRateLimitedKey('non_existent_key_'.microtime(true));
        $this->assertFalse($result);
    }
}
