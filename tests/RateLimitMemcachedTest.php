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
 * Integration tests for the Memcached backend (php-memcached native extension).
 * Requires a Memcached server reachable at memcache-server:11211.
 *
 * TTL assertions used in the Redis tests cannot be reproduced here because
 * Memcached does not expose the remaining TTL of a key. Ban-window TTL
 * correctness is verified behaviourally: after sleeping $ttl+1 seconds a
 * key created with $banTtl must still be alive (and therefore still limited),
 * while a key created with normal $ttl must have expired (and therefore free).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimitMemcachedTest.php
 */
class RateLimitMemcachedTest extends AbstractTestCase
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

    // -------------------------------------------------------------------------
    // isLimited() — basic behaviour
    // -------------------------------------------------------------------------

    public function testMemcachedTtlExpiration(): void
    {
        $limit = 2;
        $ttl = 3;
        $key = 'test'.microtime(true);
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));  // limit reached
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));

        sleep($ttl + 1); // window expires

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // new window
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));  // limit reached again
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    public function testLimitMemcached(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $key = 'test'.microtime(true);
        $limit = 2;
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

    public function testLimitMemcachedAndDeleteKey(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $key = 'test'.microtime(true);
        $limit = 1;
        $ttl = 60;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));

        $this->assertTrue($limiter->clearRateLimitedKey($key)); // key deleted

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // new window after delete
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
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
    public function testRateLimitWithBanMemcached(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $key = 'test'.microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 5;
        $banTtl = 6; // intentionally > ttl to make the behavioral distinction measurable
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // first request: not limited
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 1
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 2
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // normal TTL expires; violation_count still alive

        // violation_count >= maxAttempts: key created with banTtl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban window opens: first request free

        sleep($ttl + 1); // would expire with normal ttl, but ban window uses banTtl

        // Key created with banTtl=6 at t≈(ttl+1); at t≈2*(ttl+1) it still has ~2s left
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result); // still within ban window — confirms banTtl was applied

        sleep(4); // ban window fully expires

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban expired: back to normal window
    }

    /**
     * Step-by-step verification that mirrors testRateLimitWithBanRedisTwo.
     */
    public function testRateLimitWithBanMemcachedTwo(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
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
        $this->assertTrue($result);  // violation_count = 3 = maxAttempts

        sleep($ttl + 1); // key expires; violation_count has ~1s left
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban window: first request free
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // ban window: second request limited

        sleep($ttl + 1); // key still alive (banTtl=5, created ~3s ago)
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);  // still within ban window

        sleep(3); // ban window fully expires
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // ban expired
    }

    // -------------------------------------------------------------------------
    // $banTimeFrame expiration
    // -------------------------------------------------------------------------

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
    public function testBanTimeFrameExpirationResetsViolationsMemcached(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $key = 'test_btf_'.microtime(true);
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 6;
        $banTtl = 60; // intentionally very long — must NOT be applied after reset
        $clientIp = null;

        // Accumulate violation_count = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep($ttl + 1); // t≈3: key expired; violation_count still alive (~3s left)

        // Bring violation_count to maxAttempts (ban checked at START of call, still < maxAttempts)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // NOT limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2

        // violation_count=2 >= maxAttempts. Expires at t≈6 (banTimeFrame from t≈0).
        sleep($banTimeFrame); // t≈9: violation_count and key both expired

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // violations reset: NOT banned

        // Confirm key was created with $ttl (not $banTtl=60) by sleeping past $ttl
        sleep($ttl + 1); // key created at t≈9 with ttl=2 has now expired

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result); // new window opened: proves ttl was used, not banTtl=60
    }

    // -------------------------------------------------------------------------
    // Client IP isolation
    // -------------------------------------------------------------------------

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
    public function testClientIpIsolationMemcached(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $key = 'test_ip_iso_'.microtime(true);
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 120;
        $banTtl = 10; // intentionally > ttl to make the behavioral distinction measurable
        $clientIpA = '192.168.1.1';
        $clientIpB = '192.168.1.2';

        // --- Bring client A to ban threshold (violation_A = 2 >= maxAttempts) ---

        // Cycle 1 for A: 1 not limited + 1 limited → violation_A = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // Cycle 2 for A: 1 not limited + 1 limited → violation_A = 2 = maxAttempts
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $limiter->clearRateLimitedKey($key);

        // A: open ban window
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertFalse($result); // ban window opens: first request free

        sleep($ttl + 1); // would expire with normal ttl, but ban window uses banTtl

        // Key still alive (banTtl=10 >> ttl=2) → confirms ban is in effect for A
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpA);
        $this->assertTrue($result);
        $limiter->clearRateLimitedKey($key);

        // --- Verify client B is NOT banned (violation_B = 1 < maxAttempts) ---

        // Only 1 cycle for B → violation_B = 1
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $limiter->clearRateLimitedKey($key);

        // B: open normal window (no ban)
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertFalse($result);

        sleep($ttl + 1); // normal ttl=2 expires

        // Key gone (ttl=2) → confirms normal $ttl was applied, not $banTtl=10
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIpB);
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // clearRateLimitedKey() edge cases
    // -------------------------------------------------------------------------

    public function testClearNonExistentKeyReturnsFalse(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached);
        $result = $limiter->clearRateLimitedKey('non_existent_key_'.microtime(true));
        $this->assertFalse($result);
    }
}
