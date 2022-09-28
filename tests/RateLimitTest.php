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
 * Description of AbstractTestCase
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 * 
 * @example ./vendor/phpunit/phpunit/phpunit --verbose tests/RateLimitTest.php 
 */
class RateLimitTest extends AbstractTestCase {

    private int $port = 6379;
    private string $servername = "redis-server";
    private Client $redis;

    public function setUp(): void {
        parent::setUp();
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");
        $this->redis->flushAll();
        apcu_clear_cache();
    }

    public function tearDown(): void {
        parent::tearDown();
        $this->redis->flushAll();
        apcu_clear_cache();
    }

    public function testApcuTTLexpiration() {
        //$this->markTestSkipped();
        $limit = 2;
        $ttl = 3;
        $key = "test" . microtime(true);
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

    public function testRedisTTLexpiration() {
        //$this->markTestSkipped();
        $limit = 2;
        $ttl = 3;
        $key = "test" . microtime(true);
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");
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

    public function testLimitApcu() {
        //$this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = "test" . microtime(true);
        $limit = 2;
        $ttl = 3;
        $countFalse = 0;
        $countTrue = 0;
        $attempts = 5;
        for ($i = 0; $i < $attempts; $i++) {
            $result = $limiter->isLimited($key, $limit, $ttl);
            $countFalse = $result === false ? $countFalse + 1 : $countFalse;
            $countTrue = $result === true ? $countTrue + 1 : $countTrue;
        }
        //echo $countFalse;
        $this->assertTrue($countFalse === $limit);
        $this->assertTrue($countTrue === ($attempts - $limit));
    }

    public function testLimitRedis() {
        //$this->markTestSkipped();
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 2;
        $ttl = 3;
        $countFalse = 0;
        $countTrue = 0;
        $attempts = 5;
        for ($i = 0; $i < $attempts; $i++) {
            $result = $limiter->isLimited($key, $limit, $ttl);
            $countFalse = $result === false ? $countFalse + 1 : $countFalse;
            $countTrue = $result === true ? $countTrue + 1 : $countTrue;
        }
        //echo $countFalse;
        $this->assertTrue($countFalse === $limit);
        $this->assertTrue($countTrue === ($attempts - $limit));
    }

    public function testLimitRedisLimitOne() {
        //$this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $ttl = 3;
        $countFalse = 0;
        $countTrue = 0;
        $attempts = 5;
        for ($i = 0; $i < $attempts; $i++) {
            $result = $limiter->isLimited($key, $limit, $ttl);
            $countFalse = $result === false ? $countFalse + 1 : $countFalse;
            $countTrue = $result === true ? $countTrue + 1 : $countTrue;
        }
        //echo $countFalse;
        $this->assertTrue($countFalse === $limit);
        $this->assertTrue($countTrue === ($attempts - $limit));
    }

    public function testLimitRedisLimitOneAgain() {
        //$this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $ttl = 2;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);

        sleep($ttl);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
    }

    public function testLimitRedisLimitOneAgainTtlExpire() {
        //$this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $ttl = 20;
        $sleep = 2;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, $ttl);

        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, ($ttl - $sleep));
    }

    public function testLimitRedisLimitOneAgainTtlExpireFiveSeconds() {
        //$this->markTestSkipped();
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $ttl = 20;
        $sleep = 5;

        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, $ttl);

        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, ($ttl - $sleep));
    }

}
