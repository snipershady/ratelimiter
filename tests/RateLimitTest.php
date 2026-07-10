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
 * Integration tests for isLimited() across the APCu and Predis backends.
 * Covers basic limiting, TTL expiration, and key deletion.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimitTest.php
 */
class RateLimitTest extends AbstractTestCase
{
    private Client $redis;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Client([
            'scheme' => 'tcp',
            'host' => $this->servername,
            'port' => $this->port,
            'persistent' => true,
        ]);
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

    public function testApcuTTLexpiration(): void
    {
        // $this->markTestSkipped();
        $limit = 2;
        $ttl = 3;
        $key = 'test' . microtime(true);
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        sleep($ttl + 1);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached
    }

    public function testRedisTTLexpiration(): void
    {
        // $this->markTestSkipped();
        $limit = 2;
        $ttl = 3;
        $key = 'test' . microtime(true);
        $this->redis = new Client(sprintf('tcp://%s:%d?persistent=redis01', $this->servername, $this->port));
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        sleep($ttl + 1);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result); // limit reached
    }

    public function testLimitApcu(): void
    {
        // $this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = 'test' . microtime(true);
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
        // echo $countFalse;
        $this->assertSame($limit, $countFalse);
        $this->assertSame($attempts - $limit, $countTrue);
    }

    public function testLimitRedis(): void
    {
        // $this->markTestSkipped();
        $this->redis = new Client(sprintf('tcp://%s:%d?persistent=redis01', $this->servername, $this->port));

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
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
        // echo $countFalse;
        $this->assertSame($limit, $countFalse);
        $this->assertSame($attempts - $limit, $countTrue);
    }

    public function testLimitRedisLimitOne(): void
    {
        // $this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
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
        // echo $countFalse;
        $this->assertSame($limit, $countFalse);
        $this->assertSame($attempts - $limit, $countTrue);
    }

    public function testLimitRedisLimitOneAgain(): void
    {
        // $this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
        $limit = 1;
        $ttl = 2;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);

        sleep($ttl + 1);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
    }

    public function testLimitRedisLimitOneAgainTtlExpire(): void
    {
        // $this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
        $limit = 1;
        $ttl = 20;
        $sleep = 2;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl, $currentTtl);

        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl - $sleep, $currentTtl);
    }

    public function testLimitRedisLimitOneAgainTtlExpireFiveSeconds(): void
    {
        // $this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
        $limit = 1;
        $ttl = 20;
        $sleep = 5;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl, $currentTtl);

        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl - $sleep, $currentTtl);
    }

    public function testLimitRedisAndDeleteKey(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test' . microtime(true);
        $limit = 1;
        $ttl = 60;
        $sleep = 5;
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl, $currentTtl);
        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertSame($ttl - $sleep, $currentTtl);
        $limiter->clearRateLimitedKey($key);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
    }

    /**
     * Regression test for RateLimiterServiceRedis::isLimited(): before the
     * fix, only the very first request in a window (count <= 1) ever called
     * expire(), via the expireAndGet() transaction. If that first request's
     * process crashed between INCR and expireAndGet(), the key was left
     * incremented but with no TTL at all, permanently stuck above $limit
     * forever. isLimited() now also calls expire() with EXPIRE ... NX on
     * every subsequent request, healing a key a prior crash left without a
     * TTL, mirroring the same fix already applied to the violation counter
     * in recordViolation().
     */
    public function testMainCounterSelfHealsMissingTtlRedis(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = 'test_selfheal_main_' . microtime(true);

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

    public function testLimitApcuAndDeleteKey(): void
    {
        // $this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = 'test' . microtime(true);
        $limit = 1;
        $ttl = 60;
        $sleep = 5;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $limiter->clearRateLimitedKey($key);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
    }
}
