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
 * @example ./vendor/phpunit/phpunit/phpunit --verbose tests/RateLimitBanTest.php 
 */
class RateLimitBanTest extends AbstractTestCase {

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

    public function testRateLimitWithBanRedis() {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
        $key = "test" . microtime(true);
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 4;
        $banTtl = 10; 
        $clientIp = null;
        
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        var_dump($this->redis->ttl($key));
        
        sleep(3);
        
          $result = $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp);
        var_dump($this->redis->ttl($key));
        $this->assertEquals(10, $this->redis->ttl($key));
    }

}
