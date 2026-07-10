<?php

namespace RateLimiter\Tests\Integration\Contract;

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
 * Shared contract for the two Redis backends (Predis and php-redis). Unlike
 * APCu and Memcached, both expose the applied TTL directly via TTL/ttl(),
 * with identical semantics (remaining seconds) — so, unlike
 * AbstractRateLimiterContractTestCase's parent-level tests, the ban-lifecycle and
 * numeric-TTL assertions here can be shared verbatim between the two
 * backends instead of hand-duplicated. Before this contract existed, the
 * Predis and php-redis test files carried near-identical copies of every one
 * of these tests, and it had already caused real drift: the violation-counter
 * self-heal regression test only existed for Predis, silently leaving the
 * same php-redis code path unverified.
 *
 * $redis is intentionally untyped (object): Predis\Client and \Redis share
 * no common interface for ttl()/incr()/flushall(), but both expose them with
 * compatible call signatures, so calling through a loosely-typed property
 * here is simpler than introducing a test-only adapter for it.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractRedisFamilyContractTestCase extends AbstractRateLimiterContractTestCase
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
        apcu_clear_cache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushall();
        apcu_clear_cache();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory($this->cacheEnum(), $this->redis);
    }

    // -------------------------------------------------------------------------
    // isLimited() — numeric TTL assertions
    // -------------------------------------------------------------------------

    public function testLimitOne(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 3;
        $countFalse = 0;
        $countTrue = 0;
        $attempts = 5;

        for ($i = 0; $i < $attempts; ++$i) {
            $result = $limiter->isLimited($key, $limit, $ttl);
            $countFalse = false === $result ? $countFalse + 1 : $countFalse;
            $countTrue = $result ? $countTrue + 1 : $countTrue;
        }

        $this->assertSame($limit, $countFalse);
        $this->assertSame($attempts - $limit, $countTrue);
    }

    public function testLimitOneAgain(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 2;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));

        sleep($ttl + 1);

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
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
        $this->assertSame($ttl - $sleep, $this->redis->ttl($key));
    }

    public function testLimitOneAgainTtlExpireFiveSeconds(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 20;
        $sleep = 5;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep($sleep);
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl - $sleep, $this->redis->ttl($key));
    }

    /**
     * Overrides the behavioural-only version in AbstractRateLimiterContractTestCase
     * with one that also asserts the numeric remaining TTL, since both Redis
     * backends expose it identically via TTL/ttl().
     */
    #[\Override]
    public function testLimitAndDeleteKey(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 60;
        $sleep = 5;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep($sleep);
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
        $this->assertSame($ttl - $sleep, $this->redis->ttl($key));

        $limiter->clearRateLimitedKey($key);

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    /**
     * Regression test for isLimited(): before the fix, only the very first
     * request in a window (count <= 1) ever called expire(), via the
     * expireAndGet() transaction. If that first request's process crashed
     * between INCR and expireAndGet(), the key was left incremented but with
     * no TTL at all, permanently stuck above $limit forever. isLimited() now
     * also calls expire() with EXPIRE ... NX on every subsequent request,
     * healing a key a prior crash left without a TTL.
     */
    public function testMainCounterSelfHealsMissingTtl(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('selfheal_main');

        // Simulate a crash that incremented the counter but never reached
        // the expireAndGet() call: the key exists with no TTL at all.
        $this->redis->incr($key);
        $this->assertSame(-1, $this->redis->ttl($key)); // -1 = key exists, no TTL

        $limit = 5;
        $ttl = 30;

        $limiter->isLimited($key, $limit, $ttl);

        // The counter must now be self-healed with a real TTL instead of living forever.
        $this->assertGreaterThan(0, $this->redis->ttl($key));
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
        $banTtl = 4;
        $clientIp = null;

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // first request: not limited
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 1
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 2
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3 = maxAttempts
        $this->assertSame($ttl, $this->redis->ttl($key));

        sleep(3); // let ttl expire to enable the ban ttl
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window opens: first request of new window is free
        $this->assertSame($banTtl, $this->redis->ttl($key));

        sleep($banTtl + 1); // let ban ttl expire
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban expired: back to normal ttl window
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    /**
     * Detailed step-by-step verification of the ban state transitions.
     */
    public function testRateLimitWithBanDetailed(): void
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
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 1
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 2
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3

        sleep($ttl + 1); // normal TTL expires

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // first request of new (banned) window
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        $this->assertSame($banTtl, $this->redis->ttl($key));

        sleep($ttl + 1); // wait inside the ban window

        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // still within ban window

        sleep(3); // let ban window fully expire

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window expired, fresh start
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
        $this->assertFalse($result);                       // violations reset --> NOT banned
        $this->assertSame($ttl, $this->redis->ttl($key)); // key uses normal $ttl, not $banTtl
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
        $ttl = 60; // long enough to avoid expiry during the test
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 120;
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // --- Bring client A to ban threshold (violation_A = 2 >= maxAttempts) ---

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // violation_A=2 >= maxAttempts: key must be created with $banTtl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertSame($banTtl, $this->redis->ttl($key));
        $limiter->clearRateLimitedKey($key);

        // --- Verify client B is NOT banned (violation_B = 1 < maxAttempts) ---

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // violation_B=1 < maxAttempts: key must be created with normal $ttl
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    /**
     * Regression test: clearRateLimitedKey() alone only deletes the main request
     * counter, never the violation counter, so a banned client would be re-banned
     * on its very next request. clearBan() must clear both, so the client is
     * genuinely unbanned: the next window uses normal $ttl, not $banTtl.
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
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // let the normal window expire

        // Ban window opens: fresh key created with banTtl.
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertSame($banTtl, $this->redis->ttl($key)); // confirms the client is genuinely banned

        $this->assertTrue($limiter->clearBan($key, $clientIp));

        // Genuinely unbanned: fresh window uses normal $ttl, not $banTtl.
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $this->assertSame($ttl, $this->redis->ttl($key));
    }

    /**
     * Regression test for recordViolation(): before the fix, the violation
     * counter's increment and expire were two separate, unbatched Redis
     * calls. A crash between them left the counter live forever with no TTL,
     * permanently stuck above maxAttempts. recordViolation() now calls
     * expire() with EXPIRE ... NX, which only binds a TTL if the key doesn't
     * already have one — so any later violation "heals" a counter a prior
     * crash left without a TTL.
     */
    public function testViolationCounterSelfHealsMissingTtl(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('selfheal_violation');
        $violationCountKey = 'BAN_violation_count_' . $key;

        // Simulate a crash that incremented the violation counter but never
        // reached the expire() call: the key exists with no TTL at all.
        $this->redis->incr($violationCountKey);
        $this->assertSame(-1, $this->redis->ttl($violationCountKey)); // -1 = key exists, no TTL

        $limit = 1;
        $maxAttempts = 5; // high enough that these violations alone won't trigger a ban
        $ttl = 60;
        $banTimeFrame = 30;
        $banTtl = 120;

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, null); // not limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, null); // limited -> records a violation

        // The counter must now be self-healed with a real TTL instead of living forever.
        $this->assertGreaterThan(0, $this->redis->ttl($violationCountKey));
    }
}
