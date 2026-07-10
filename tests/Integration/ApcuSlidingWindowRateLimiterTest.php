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
 * Integration tests for the APCU backend with AlgorithmEnum::SLIDING_WINDOW
 * (RateLimiterServiceAPCuSlidingWindow). Behaviour shared with the Memcached
 * sliding backend lives in AbstractSlidingWindowCounterContractTestCase.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Integration/ApcuSlidingWindowRateLimiterTest.php
 */
class ApcuSlidingWindowRateLimiterTest extends AbstractSlidingWindowCounterContractTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        apcu_clear_cache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        apcu_clear_cache();
    }

    #[\Override]
    protected function makeLimiter(): AbstractRateLimiterService
    {
        return AbstractRateLimiterService::factory(CacheEnum::APCU, null, AlgorithmEnum::SLIDING_WINDOW);
    }
}
