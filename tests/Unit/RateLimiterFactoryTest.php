<?php

namespace RateLimiter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
use RateLimiter\Service\RateLimiterServiceAPCu;
use RateLimiter\Service\RateLimiterServiceMemcached;
use RateLimiter\Service\RateLimiterServiceRedis;

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
 * Unit tests for AbstractRateLimiterService::factory(): backend resolution and
 * the client-type guards for REDIS/PHP_REDIS/MEMCACHED. Pure unit tests — none
 * of the constructors involved ever connect to a server, so no external
 * service (APCu, Redis, Memcached) is required to run this file.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Unit/RateLimiterFactoryTest.php
 */
class RateLimiterFactoryTest extends TestCase
{
    public function testFactoryApcuReturnsApcuService(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $this->assertInstanceOf(RateLimiterServiceAPCu::class, $limiter);
    }

    public function testFactoryRedisReturnsRedisServiceWithPredisClient(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, new Client());
        $this->assertInstanceOf(RateLimiterServiceRedis::class, $limiter);
    }

    public function testFactoryRedisWithoutClientThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Predis\Client required for REDIS backend');
        AbstractRateLimiterService::factory(CacheEnum::REDIS);
    }

    /**
     * A \Redis instance (the PHP_REDIS backend's client) must not be silently
     * accepted for the REDIS (Predis) backend — the two are not interchangeable.
     */
    public function testFactoryRedisWithWrongClientTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AbstractRateLimiterService::factory(CacheEnum::REDIS, new \Redis());
    }

    public function testFactoryPhpRedisReturnsRedisServiceWithRedisClient(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, new \Redis());
        $this->assertInstanceOf(RateLimiterServiceRedis::class, $limiter);
    }

    public function testFactoryPhpRedisWithoutClientThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('\Redis instance required for PHP_REDIS backend');
        AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS);
    }

    /**
     * A Predis\Client (the REDIS backend's client) must not be silently
     * accepted for the PHP_REDIS backend.
     */
    public function testFactoryPhpRedisWithWrongClientTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, new Client());
    }

    public function testFactoryMemcachedReturnsMemcachedService(): void
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, new \Memcached());
        $this->assertInstanceOf(RateLimiterServiceMemcached::class, $limiter);
    }

    public function testFactoryMemcachedWithoutClientThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('\Memcached instance required for MEMCACHED backend');
        AbstractRateLimiterService::factory(CacheEnum::MEMCACHED);
    }

    public function testFactoryMemcachedWithWrongClientTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, new \Redis());
    }
}
