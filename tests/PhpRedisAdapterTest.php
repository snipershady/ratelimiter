<?php

namespace RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use RateLimiter\Adapter\PhpRedisAdapter;

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
 * Unit tests for PhpRedisAdapter in isolation, against a mocked \Redis client.
 * A pure unit test suite: no live Redis server is needed, and it runs in
 * milliseconds instead of the multi-second sleeps required by the integration
 * suite (RateLimitPhpRedisTest / RateLimitPhpRedisBanTest).
 *
 * It exists specifically to reach failure branches that a real, healthy,
 * single-process Redis server essentially never produces on demand — e.g. the
 * EXPIRE/GET transaction itself failing outright (as opposed to a WRONGTYPE
 * reply inside it), which requires the connection to drop mid-MULTI/EXEC.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/PhpRedisAdapterTest.php
 */
class PhpRedisAdapterTest extends TestCase
{
    public function testIncrementReturnsIncrementedValue(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('clearLastError');
        $redis->expects($this->once())->method('incr')->with('key')->willReturn(3);

        $adapter = new PhpRedisAdapter($redis);

        $this->assertSame(3, $adapter->increment('key'));
    }

    /**
     * \Redis::incr() has no legitimate false outcome (a missing key is
     * created at 1), so any false return is a genuine backend error and must
     * fail closed rather than being cast to 0.
     */
    public function testIncrementThrowsOnBackendError(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('incr')->willReturn(false);
        $redis->method('getLastError')->willReturn('WRONGTYPE Operation against a key holding the wrong kind of value');

        $adapter = new PhpRedisAdapter($redis);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis INCR failed for key "key"');
        $adapter->increment('key');
    }

    public function testExpireAndGetReturnsCurrentValue(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('expire')->willReturnSelf();
        $redis->method('get')->willReturnSelf();
        $redis->method('exec')->willReturn([true, '4']);

        $adapter = new PhpRedisAdapter($redis);

        $this->assertSame(4, $adapter->expireAndGet('key', 30));
    }

    /**
     * Regression coverage: the EXPIRE/GET transaction itself failing (exec()
     * returning false, e.g. a dropped connection mid-MULTI/EXEC) must fail
     * closed. This branch is not reachable through the integration suite
     * against a healthy live server.
     */
    public function testExpireAndGetThrowsWhenTransactionFails(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('expire')->willReturnSelf();
        $redis->method('get')->willReturnSelf();
        $redis->method('exec')->willReturn(false);
        $redis->method('getLastError')->willReturn('connection lost');

        $adapter = new PhpRedisAdapter($redis);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis EXPIRE/GET transaction failed for key "key"');
        $adapter->expireAndGet('key', 30);
    }

    /**
     * The key is guaranteed to exist at this point (increment() just
     * created/bumped it), so a false GET result inside the transaction can
     * only be a genuine error, never a legitimate miss.
     */
    public function testExpireAndGetThrowsWhenGetResultIsFalse(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('expire')->willReturnSelf();
        $redis->method('get')->willReturnSelf();
        $redis->method('exec')->willReturn([true, false]);
        $redis->method('getLastError')->willReturn('WRONGTYPE ...');

        $adapter = new PhpRedisAdapter($redis);

        $this->expectException(\RuntimeException::class);
        $adapter->expireAndGet('key', 30);
    }

    public function testGetReturnsZeroOnLegitimateCacheMiss(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn(false);
        $redis->method('getLastError')->willReturn(null);

        $adapter = new PhpRedisAdapter($redis);

        $this->assertSame(0, $adapter->get('missing'));
    }

    public function testGetReturnsValueOnHit(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn('7');

        $adapter = new PhpRedisAdapter($redis);

        $this->assertSame(7, $adapter->get('key'));
    }

    /**
     * A false GET result is ambiguous by itself (miss vs. WRONGTYPE); only
     * getLastError() disambiguates. This must fail closed, not be silently
     * treated as a count of 0.
     */
    public function testGetThrowsOnGenuineBackendError(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn(false);
        $redis->method('getLastError')->willReturn('WRONGTYPE Operation against a key holding the wrong kind of value');

        $adapter = new PhpRedisAdapter($redis);

        $this->expectException(\RuntimeException::class);
        $adapter->get('key');
    }

    public function testExpireDelegatesWithNxFlag(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('expire')->with('key', 60, 'NX');

        $adapter = new PhpRedisAdapter($redis);
        $adapter->expire('key', 60);
    }

    public function testDelCastsResultToInt(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('del')->willReturn(1);

        $adapter = new PhpRedisAdapter($redis);

        $this->assertSame(1, $adapter->del('key'));
    }
}
