<?php

namespace RateLimiter\Tests;

use ErrorException;
use Exception;
use Predis\Client;
use RateLimiter\Enum\AlgorithmStrategyEnum;
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
 * @example ./vendor/bin/phpunit --verbose tests/RateLimitWindowLogTest.php
 */
class RateLimitWindowLogTest extends AbstractTestCase {

    private int $port = 6379;
    private string $servername = "redis-server";
    private Client $redis;

    public static function setUpBeforeClass(): void {
        // errors will be handled as ErrorException
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            // error was suppressed with the @-operator
            if (error_reporting() === 0) {
                return false;
            }

            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            apcu_cache_info();
        } catch (Exception $ex) {
            echo PHP_EOL . $ex->getMessage() . PHP_EOL;
            echo PHP_EOL . "[APCU]" . PHP_EOL . " apc.enable_cli=1" . PHP_EOL;
            exit;
        }
    }

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

    public function testAllowedReturnsTrueAfterWindowExpires(): void {
        $limit = 10;
        $ttl = 4;
        $ttlMills = $ttl * 1000;
        $key = "test" . microtime(true);
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis, AlgorithmStrategyEnum::SLIDING_LOG);

        // Simulate 10 requests inside the window
        for ($i = 0; $i < $limit; $i++) {
            $this->assertFalse($limiter->isLimited($key, $limit, $ttlMills));
        }

        // wait a new window
        sleep($ttl + 1);

        // inside the new window we expect to be not limited
        $this->assertFalse($limiter->isLimited($key, $limit, $ttlMills));
    }

    public function testAllowedReturnsFalseWhenExceedingLimit(): void {
        $limit = 10;
        $ttl = 4;
        $ttlMills = $ttl * 1000;
        $key = "test" . microtime(true);
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis, AlgorithmStrategyEnum::SLIDING_LOG);

        for ($i = 0; $i < $limit; $i++) {
            //echo PHP_EOL . "Request count: " . $i + 1 . PHP_EOL;
            $this->assertFalse($limiter->isLimited($key, $limit, $ttlMills));
        }

        $this->assertTrue($limiter->isLimited($key, $limit, $ttlMills));
    }

    public function testAllowedReturnsTrueWhenWithinLimit(): void {
        $limit = 10;
        $ttl = 4;
        $ttlMills = $ttl * 1000;
        $key = "test" . microtime(true);
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis, AlgorithmStrategyEnum::SLIDING_LOG);

        // Simula 9 richieste entro la finestra
        for ($i = 0; $i <= $limit - 1; $i++) {
            $this->assertFalse($limiter->isLimited($key, $limit, $ttlMills));
        }
    }

    public function testAllowedReturnsTrueWhenWithinLimitLimitOne(): void {
        $limit = 1;
        $ttl = 4;
        $ttlMills = $ttl * 1000;
        $key = "test" . microtime(true);
        $this->redis = new Client("tcp://$this->servername:$this->port?persistent=redis01");

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis, AlgorithmStrategyEnum::SLIDING_LOG);

        // Simula 9 richieste entro la finestra
        for ($i = 0; $i <= $limit - 1; $i++) {
            echo PHP_EOL . "Request count: " . $i + 1 . PHP_EOL;
            $this->assertFalse($limiter->isLimited($key, $limit, $ttlMills));
        }
        
        $this->assertTrue($limiter->isLimited($key, $limit, $ttlMills));
    }
}
