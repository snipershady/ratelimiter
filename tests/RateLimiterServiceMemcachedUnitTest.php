<?php

namespace RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use RateLimiter\Service\RateLimiterServiceMemcached;

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
 * Unit tests for RateLimiterServiceMemcached::atomicIncrement() against a
 * mocked \Memcached client. A pure unit test suite: no live Memcached server
 * is needed and nothing sleeps.
 *
 * It exists specifically to reach the "lost the add() race" retry path: the
 * sequence where increment() misses (key doesn't exist yet), a concurrent
 * process creates the key before our add() runs, and we must retry
 * increment() once more. That interleaving cannot be reliably forced against
 * a live, single-process Memcached server in a deterministic test, so it is
 * simulated here instead. Complements RateLimitMemcachedTest (integration
 * tests against a live Memcached server).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimiterServiceMemcachedUnitTest.php
 */
class RateLimiterServiceMemcachedUnitTest extends TestCase
{
    /**
     * Returns a callback usable as a mock return-value callback: it yields
     * each value in $values in order, then keeps repeating the last one.
     * Used for getResultCode()/getResultMessage(), which are called at
     * different decision points that each need a specific value, plus
     * possibly again from memcachedFailure()'s message formatting.
     *
     * @param list<int> $values
     */
    private static function sequence(array $values): \Closure
    {
        return static function () use (&$values) {
            return \count($values) > 1 ? array_shift($values) : $values[0];
        };
    }

    /**
     * increment() misses (RES_NOTFOUND), a concurrent process wins the race
     * and creates the key before our add() runs (RES_NOTSTORED), so we retry
     * increment() — which now succeeds against the concurrently-created key.
     * Covers the previously-untested "lost the race, retry succeeds" path.
     */
    public function testLostAddRaceRetriesIncrementSuccessfully(): void
    {
        $memcached = $this->createMock(\Memcached::class);
        $memcached->expects($this->exactly(2))->method('increment')
            ->with('key')
            ->willReturnOnConsecutiveCalls(false, 2);
        $memcached->expects($this->once())->method('add')->willReturn(false);
        $memcached->method('getResultCode')->willReturnCallback(self::sequence([
            \Memcached::RES_NOTFOUND,
            \Memcached::RES_NOTSTORED,
        ]));

        $service = new RateLimiterServiceMemcached($memcached);

        $this->assertFalse($service->isLimited('key', 5, 10)); // count=2 <= limit=5
    }

    /**
     * Same lost-race interleaving, but the retried increment() also fails
     * (e.g. the concurrently-created key vanished again) — must fail closed.
     */
    public function testLostAddRaceThenRetryIncrementFailsThrows(): void
    {
        $memcached = $this->createStub(\Memcached::class);
        $memcached->method('increment')->willReturnOnConsecutiveCalls(false, false);
        $memcached->method('add')->willReturn(false);
        $memcached->method('getResultCode')->willReturnCallback(self::sequence([
            \Memcached::RES_NOTFOUND,
            \Memcached::RES_NOTSTORED,
        ]));
        $memcached->method('getResultMessage')->willReturn('mocked failure');

        $service = new RateLimiterServiceMemcached($memcached);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memcached increment() failed for key "key"');
        $service->isLimited('key', 5, 10);
    }

    /**
     * increment() misses (RES_NOTFOUND) and add() then fails with a genuine
     * backend error (not RES_NOTSTORED, i.e. not merely "lost the race") —
     * must fail closed instead of being mistaken for a lost race.
     */
    public function testAddGenuineFailureThrows(): void
    {
        $memcached = $this->createStub(\Memcached::class);
        $memcached->method('increment')->willReturn(false);
        $memcached->method('add')->willReturn(false);
        $memcached->method('getResultCode')->willReturnCallback(self::sequence([
            \Memcached::RES_NOTFOUND,
            \Memcached::RES_SERVER_ERROR,
        ]));
        $memcached->method('getResultMessage')->willReturn('mocked failure');

        $service = new RateLimiterServiceMemcached($memcached);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memcached add() failed for key "key"');
        $service->isLimited('key', 5, 10);
    }

    /**
     * increment() fails with a genuine backend error on the very first call
     * (not RES_NOTFOUND, i.e. not a simple cache miss) — must fail closed
     * immediately, without ever attempting add().
     */
    public function testIncrementGenuineFailureThrowsWithoutAttemptingAdd(): void
    {
        $memcached = $this->createMock(\Memcached::class);
        $memcached->expects($this->once())->method('increment')->willReturn(false);
        $memcached->expects($this->never())->method('add');
        $memcached->method('getResultCode')->willReturn(\Memcached::RES_SERVER_ERROR);
        $memcached->method('getResultMessage')->willReturn('mocked failure');

        $service = new RateLimiterServiceMemcached($memcached);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memcached increment() failed for key "key"');
        $service->isLimited('key', 5, 10);
    }

    /**
     * The straightforward "key doesn't exist yet, add() wins" path — no
     * concurrent writer involved.
     */
    public function testFirstRequestCreatesKeyViaAdd(): void
    {
        $memcached = $this->createStub(\Memcached::class);
        $memcached->method('increment')->willReturn(false);
        $memcached->method('getResultCode')->willReturn(\Memcached::RES_NOTFOUND);
        $memcached->method('add')->willReturn(true);

        $service = new RateLimiterServiceMemcached($memcached);

        $this->assertFalse($service->isLimited('key', 5, 10));
    }
}
