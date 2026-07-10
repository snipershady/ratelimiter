<?php

namespace RateLimiter\Tests\Integration;

use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
use RateLimiter\Tests\Integration\Contract\AbstractRateLimiterContractTestCase;

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
 * Integration tests for the APCU backend. TTL-expiration, limit-counting and
 * clear-nonexistent-key behaviour are inherited from
 * AbstractRateLimiterContractTestCase. The ban-lifecycle tests below stay local
 * (rather than living in the shared Redis-family contract) because APCu's
 * only way to introspect the applied TTL, apcu_key_info($key)['ttl'], reports
 * the TTL set at key creation — not the remaining TTL the way Redis's ttl()
 * does — so the assertion shape genuinely differs from the Redis-family one.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/ApcuRateLimiterTest.php
 */
class ApcuRateLimiterTest extends AbstractRateLimiterContractTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        apcu_clear_cache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        apcu_clear_cache();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory(CacheEnum::APCU);
    }

    // -------------------------------------------------------------------------
    // isLimitedWithBan() — basic lifecycle
    // -------------------------------------------------------------------------

    public function testRateLimitWithBan(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 5;
        $clientIp = null;

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));

        sleep($ttl + 1);
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);

        sleep($ttl + 1);
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);
    }

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
    public function testBanTimeFrameExpirationResetsViolations(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('btf');
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

    /**
     * Each clientIp has an independent violation counter. Client A reaching the
     * ban threshold must not affect Client B whose counter is still below maxAttempts.
     */
    public function testClientIpIsolation(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('ip_iso');
        $limit = 1;
        $ttl = 60;
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 120;
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // violation_A=2 >= maxAttempts: key must be created with $banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);
        $limiter->clearRateLimitedKey($key);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // violation_B=1 < maxAttempts: key must be created with normal $ttl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertSame($ttl, apcu_key_info($key)['ttl']);
    }

    /**
     * Regression test: clearRateLimitedKey() alone only deletes the main request
     * counter, never the violation counter, so a banned client would be re-banned
     * on its very next request. clearBan() must clear both.
     */
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
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);

        sleep($ttl + 1);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertSame($banTtl, apcu_key_info($key)['ttl']);

        $this->assertTrue($limiter->clearBan($key, $clientIp));

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $this->assertSame($ttl, apcu_key_info($key)['ttl']);
    }
}
