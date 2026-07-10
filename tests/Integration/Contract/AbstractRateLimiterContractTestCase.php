<?php

namespace RateLimiter\Tests\Integration\Contract;

use RateLimiter\Service\AbstractRateLimiterService;
use RateLimiter\Tests\AbstractTestCase;

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
 * Shared contract for behaviour that is identical across every cache backend:
 * basic TTL-window expiration, limit counting, and the "nothing to clear"
 * edge cases of clearRateLimitedKey()/clearBan(). Every concrete backend test
 * class (Apcu/Predis/PhpRedis/Memcached) extends this — directly, or via
 * AbstractRedisFamilyContractTestCase — and inherits these tests for free, so a
 * behavioural fix or a new assertion added here automatically applies to all
 * backends instead of risking silent drift between hand-duplicated copies
 * (see the "clearBan on a never-banned key" gap that existed only for APCu
 * before this contract was introduced).
 *
 * Backend-specific behaviour (ban lifecycle, TTL introspection, error
 * handling quirks) is NOT here: APCu and Memcached expose the applied TTL
 * differently from each other and from Redis (see AbstractRedisFamilyContractTestCase
 * and the concrete Apcu/Memcached test classes for why those are not folded
 * into this base too).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractRateLimiterContractTestCase extends AbstractTestCase
{
    abstract protected function makeLimiter(): AbstractRateLimiterService;

    protected function uniqueKey(string $prefix = 'test'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    public function testTtlExpiration(): void
    {
        $limit = 2;
        $ttl = 3;
        $key = $this->uniqueKey();
        $limiter = $this->makeLimiter();

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached

        sleep($ttl + 1);

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // new window
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached again
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    public function testLimitCounting(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
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

    public function testClearNonExistentKeyReturnsFalse(): void
    {
        $limiter = $this->makeLimiter();
        $result = $limiter->clearRateLimitedKey($this->uniqueKey('non_existent'));
        $this->assertFalse($result);
    }

    /**
     * Purely behavioural version (no TTL introspection): valid for every
     * backend as-is. AbstractRedisFamilyContractTestCase overrides this with a
     * variant that also asserts the numeric TTL, since Predis/php-redis can
     * expose it and APCu/Memcached either can't (Memcached) or expose a
     * different notion of it (APCu: TTL-at-creation, not remaining).
     */
    public function testLimitAndDeleteKey(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $ttl = 60;
        $sleep = 5;

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));

        sleep($sleep);
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // ttl=60 not yet expired

        $this->assertTrue($limiter->clearRateLimitedKey($key));

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // new window after delete
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    /**
     * clearBan()'s "mainCleared || violationCleared" logic is implemented
     * separately per backend, so a key that was never limited/banned must
     * return false on every backend, not just the one it happened to be
     * written against first.
     */
    public function testClearBanNonExistentKeyReturnsFalse(): void
    {
        $limiter = $this->makeLimiter();
        $result = $limiter->clearBan($this->uniqueKey('non_existent'));
        $this->assertFalse($result);
    }
}
