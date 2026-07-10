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
 * Integration tests for the Memcached backend (php-memcached native
 * extension). Requires a Memcached server reachable at memcache-server:11211.
 *
 * TTL-expiration, limit-counting and clear-nonexistent-key behaviour are
 * inherited from AbstractRateLimiterContractTestCase. The ban-lifecycle tests
 * below stay local (rather than living in the shared Redis-family contract)
 * because Memcached does not expose the remaining TTL of a key at all —
 * unlike Redis's ttl() or even APCu's creation-time apcu_key_info(). Ban
 * window correctness is instead verified behaviourally: after sleeping
 * $ttl+1 seconds, a key created with $banTtl must still be alive (and
 * therefore still limited), while a key created with normal $ttl must have
 * expired (and therefore be free).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/MemcachedRateLimiterTest.php
 */
class MemcachedRateLimiterTest extends AbstractRateLimiterContractTestCase
{
    private \Memcached $memcached;

    /**
     * Skips the entire suite (with markTestSkipped) when the memcached
     * extension is missing or the server is unreachable. Does NOT call
     * parent::setUpBeforeClass() to avoid the APCu check that is irrelevant
     * for a Memcached-only test class.
     */
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): false {
            if (0 === error_reporting()) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        if (!extension_loaded('memcached')) {
            self::markTestSkipped('The memcached extension is not available.');
        }

        $memcached = new \Memcached();
        $memcached->addServer('memcache-server', 11211);

        $versions = $memcached->getVersion();

        if (false === $versions || \in_array(false, $versions, true)) {
            self::markTestSkipped('Cannot connect to Memcached at memcache-server:11211.');
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->memcached = new \Memcached('rl_test');
        if (!$this->memcached->getServerList()) {
            $this->memcached->addServer('memcache-server', 11211);
        }
        $this->memcached->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->memcached->flush();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
    }

    // -------------------------------------------------------------------------
    // isLimitedWithBan() — basic lifecycle
    // -------------------------------------------------------------------------

    /**
     * Verifies the full ban lifecycle:
     *   1. Accumulate violations up to maxAttempts.
     *   2. Ban window opens: key is created with banTtl.
     *   3. Sleeping $ttl+1 seconds does NOT expire the key (banTtl > ttl) — it is
     *      still limited, confirming banTtl was applied.
     *   4. After the full ban window expires, the client returns to normal behaviour.
     */
    public function testRateLimitWithBan(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 5;
        $banTtl = 6; // intentionally > ttl to make the behavioral distinction measurable
        $clientIp = null;

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // first request: not limited
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 1
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 2
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // normal TTL expires; violation_count still alive

        // violation_count >= maxAttempts: key created with banTtl
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window opens: first request free

        sleep($ttl + 1); // would expire with normal ttl, but ban window uses banTtl

        // Key created with banTtl=6 at t≈(ttl+1); at t≈2*(ttl+1) it still has ~2s left
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // still within ban window — confirms banTtl was applied

        sleep(4); // ban window fully expires

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban expired: back to normal window
    }

    /**
     * Step-by-step verification mirroring AbstractRedisFamilyContractTestCase::testRateLimitWithBanDetailed.
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
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // key expires; violation_count has ~1s left
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window: first request free
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // ban window: second request limited

        sleep($ttl + 1); // key still alive (banTtl=5, created ~3s ago)
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // still within ban window

        sleep(3); // ban window fully expires
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban expired
    }

    /**
     * After $banTimeFrame seconds the violation counter expires and the client is
     * no longer considered for banning. The next key must be created with $ttl
     * (not $banTtl), verified behaviourally: after a further sleep($ttl+1) the
     * key must have expired (assertFalse), whereas with $banTtl=60 it would still
     * be alive (assertTrue).
     *
     * Timeline (banTimeFrame=6, ttl=2, banTtl=60):
     *   t=0   req1: NOT limited.  req2: LIMITED → violation_count=1, TTL=6s
     *   t=3   sleep(ttl+1): key expired; violation_count has ~3s left
     *   t=3   req3: NOT limited.  req4: LIMITED → violation_count=2 = maxAttempts
     *   t=9   sleep(banTimeFrame): violation_count expired at t≈6; key also expired
     *   t=9   req5: violation_count=0 → NOT banned; key created with $ttl=2, not $banTtl=60
     *   t=12  sleep(ttl+1): key created with $ttl=2 at t≈9 has expired
     *   t=12  req6: new window → assertFalse confirms $ttl was used, not $banTtl
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

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep($ttl + 1); // t≈3: key expired; violation_count still alive (~3s left)

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // NOT limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2

        sleep($banTimeFrame); // t≈9: violation_count and key both expired

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // violations reset: NOT banned

        sleep($ttl + 1); // key created at t≈9 with ttl=2 has now expired

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // new window opened: proves ttl was used, not banTtl=60
    }

    /**
     * Violation counters are per-clientIp. Client A reaching the ban threshold
     * must not affect Client B whose violation counter is below maxAttempts.
     *
     * TTL correctness is verified behaviourally:
     *   - After the ban window for A opens, sleeping $ttl+1 seconds still leaves
     *     the key alive (banTtl > ttl) → assertTrue confirms ban is in effect.
     *   - For B (not banned), sleeping $ttl+1 seconds after the normal window
     *     opens expires the key → assertFalse confirms normal $ttl was used.
     */
    public function testClientIpIsolation(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('ip_iso');
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 10; // intentionally > ttl to make the behavioral distinction measurable
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // --- Bring client A to ban threshold (violation_A = 2 >= maxAttempts) ---

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA)); // ban window opens: first request free

        sleep($ttl + 1); // would expire with normal ttl, but ban window uses banTtl

        // Key still alive (banTtl=10 >> ttl=2) → confirms ban is in effect for A
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA));
        $limiter->clearRateLimitedKey($key);

        // --- Verify client B is NOT banned (violation_B = 1 < maxAttempts) ---

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB));

        sleep($ttl + 1); // normal ttl=2 expires

        // Key gone (ttl=2) → confirms normal $ttl was applied, not $banTtl=10
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB));
    }

    /**
     * Same clearBan() regression as the Redis/APCu equivalents, verified
     * behaviourally: after clearBan(), a normal-$ttl window must actually
     * expire on schedule instead of surviving like a banTtl-backed window would.
     */
    public function testClearBanActuallyUnbansClient(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('clearban');
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 30;
        $banTtl = 10; // intentionally > ttl to make the behavioral distinction measurable
        $clientIp = '203.0.113.9';

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);

        sleep($ttl + 1); // let the normal window expire

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // ban window opens: first request free

        $this->assertTrue($limiter->clearBan($key, $clientIp));

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // fresh window after clearBan

        sleep($ttl + 1); // would survive if banTtl=10 had been (wrongly) reapplied

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // key expired with normal $ttl, proving the client was genuinely unbanned
    }

    // -------------------------------------------------------------------------
    // Backend failure handling — specific to Memcached
    // -------------------------------------------------------------------------

    /**
     * Regression test for atomicIncrement(): before the fix, a genuine
     * Memcached backend error (as opposed to a simple cache miss) was
     * indistinguishable from "key doesn't exist yet" and silently treated as
     * the first-ever request, letting traffic through unlimited (fail-open).
     * A rate limiter is a security control, so a real backend error must now
     * fail closed by throwing instead.
     */
    public function testMemcachedFailsClosedOnBackendError(): void
    {
        $memcached = new \Memcached('rl_test_unreachable_' . microtime(true));
        // Tight timeouts so the test fails fast instead of hanging.
        $memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 200);
        $memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, 200000);
        $memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, 200000);
        $memcached->setOption(\Memcached::OPT_POLL_TIMEOUT, 200);
        $memcached->addServer('127.0.0.1', 19999); // nothing listens here

        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $memcached);

        $this->expectException(\RuntimeException::class);
        $limiter->isLimited($this->uniqueKey('unreachable'), 5, 60);
    }

    /**
     * Regression test for normalizeTtl(): Memcached's protocol treats an
     * exptime greater than 30 days (2,592,000s) as an absolute Unix timestamp
     * rather than a relative offset. Before the fix, a TTL just over that
     * threshold was silently reinterpreted as a moment already in the past
     * (1970-01-30 UTC), so the key was effectively never actually stored.
     */
    public function testMemcachedTtlAboveThirtyDaysIsNormalized(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('ttl30d');
        $overThirtyDays = 2_592_001; // 30 days + 1 second

        $limiter->isLimited($key, 5, $overThirtyDays);

        // The key must actually persist with a real future expiry instead of
        // being dropped/never stored.
        $this->assertSame(1, (int) $this->memcached->get($key));
    }
}
