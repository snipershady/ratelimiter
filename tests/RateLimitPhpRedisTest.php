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
 * Description of AbstractTestCase.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimitPhpRedisTest.php
 */
class RateLimitPhpRedisTest extends AbstractTestCase
{
    private int $port = 6379;
    private string $servername = 'redis-server';
    private \Redis $redis;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        set_error_handler(function ($errno, $errstr, $errfile, $errline): false {
            // error was suppressed with the @-operator
            if (0 === error_reporting()) {
                return false;
            }

            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            apcu_cache_info();
        } catch (\Exception $ex) {
            echo PHP_EOL.$ex->getMessage().PHP_EOL;
            echo PHP_EOL.'[APCU]'.PHP_EOL.' apc.enable_cli=1'.PHP_EOL;
            exit;
        }
    }

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $this->redis = new \Redis();
        $this->redis->pconnect(
            $this->servername, // host
            $this->port, // port
            2, // connectTimeout
            'persistent_id_rl_test'         // persistent_id
        );

        $this->redis->flushall();
        apcu_clear_cache();
    }

    #[\Override]
    public function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushall();
    }

    public function testRedisTTLexpiration(): void
    {
        // $this->markTestSkipped();

        $limit = 2;
        $ttl = 3;
        $key = 'test'.microtime(true).'_'.__METHOD__;

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);

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

    public function testLimitRedis(): void
    {
        // $this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
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
        $this->assertTrue($countFalse === $limit);
        $this->assertTrue($countTrue === ($attempts - $limit));
    }

    public function testLimitRedisLimitOne(): void
    {
        // $this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
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
        $this->assertTrue($countFalse === $limit);
        $this->assertTrue($countTrue === ($attempts - $limit));
    }

    public function testLimitRedisLimitOneAgain(): void
    {
        // $this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
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

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
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
        $this->assertEquals($currentTtl, $ttl - $sleep);
    }

    public function testLimitRedisLimitOneAgainTtlExpireFiveSeconds(): void
    {
        // $this->markTestSkipped();

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
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
        $this->assertEquals($currentTtl, $ttl - $sleep);
    }

    public function testLimitRedisAndDeleteKey(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $this->redis);
        $key = 'test'.microtime(true).'_'.__METHOD__;
        $limit = 1;
        $ttl = 60;
        $sleep = 5;
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, $ttl);
        sleep($sleep);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
        $currentTtl = $this->redis->ttl($key);
        $this->assertEquals($currentTtl, $ttl - $sleep);
        $limiter->clearRateLimitedKey($key);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertFalse($result);
        $result = $limiter->isLimited($key, $limit, $ttl);
        $this->assertTrue($result);
    }
}
