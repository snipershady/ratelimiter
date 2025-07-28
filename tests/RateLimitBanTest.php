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
 * @example ./vendor/bin/phpunit tests/RateLimitBanTest.php
 */
class RateLimitBanTest extends AbstractTestCase {

    private int $port = 6379;
    private string $servername = "redis-server";
    private Client $redis;

    public function setUp(): void {
        parent::setUp();
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");
        $this->redis->flushall();
        apcu_clear_cache();
    }

    public function tearDown(): void {
        parent::tearDown();
        $this->redis->flushall();
        apcu_clear_cache();
    }

    public function testRateLimitWithBanRedis(): void {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 4;
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertEquals($ttl, $this->redis->ttl($key));

        sleep(3); // let ttl expire to enable the ban ttl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);

        $this->assertEquals($banTtl, $this->redis->ttl($key));
        sleep($banTtl + 1); //let expire ban ttl
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        
        $this->assertEquals($ttl, $this->redis->ttl($key)); // ttl is again the nominal value not banned
        $this->assertFalse($result);
    }

    public function testRateLimitWithBaRedis(): void {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 5;
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);

        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertTrue($this->redis->ttl($key) === $banTtl);
        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        sleep(3);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
    }

    public function testRateLimitWithBanAPCu(): void {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = "test" . microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 5;
        $clientIp = null;

        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);

        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertFalse($result);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertTrue(apcu_key_info($key)["ttl"] === $banTtl);
        sleep($ttl + 1);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $this->assertTrue($result);
        $this->assertTrue(apcu_key_info($key)["ttl"] === $banTtl);
    }

}
