<?php

declare(strict_types=1);

namespace RateLimiter\Tests\Integration;

use RateLimiter\Enum\AlgorithmEnum;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
use RateLimiter\Tests\Integration\Contract\AbstractSlidingWindowCounterContractTestCase;

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
 * Integration tests for the Memcached backend with AlgorithmEnum::SLIDING_WINDOW
 * (RateLimiterServiceMemcachedSlidingWindow). Requires a Memcached server
 * reachable at memcache-server:11211. Behaviour shared with the APCu sliding
 * backend lives in AbstractSlidingWindowCounterContractTestCase.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/MemcachedSlidingWindowRateLimiterTest.php
 */
class MemcachedSlidingWindowRateLimiterTest extends AbstractSlidingWindowCounterContractTestCase
{
    private \Memcached $memcached;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): false {
            if (0 === error_reporting()) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        if (!extension_loaded('memcached')) {
            self::markTestSkipped('The memcached extension is not available.');
        }

        $memcached = new \Memcached();
        $memcached->addServer('memcache-server', 11211);

        $versions = $memcached->getVersion();

        if (false === $versions || \in_array(false, $versions, true)) {
            self::markTestSkipped('Cannot connect to Memcached at memcache-server:11211.');
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->memcached = new \Memcached('rl_test_sliding');
        if (!$this->memcached->getServerList()) {
            $this->memcached->addServer('memcache-server', 11211);
        }
        $this->memcached->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->memcached->flush();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $this->memcached, AlgorithmEnum::SLIDING_WINDOW);
    }
}
