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
 * Tests for input validation across all public methods.
 * Validation logic lives in AbstractRateLimiterService and is shared by all backends,
 * so APCu is used as the test backend for simplicity.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/RateLimiterValidationTest.php
 */
class RateLimiterValidationTest extends AbstractTestCase
{
    private AbstractRateLimiterService $limiter;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        apcu_clear_cache();
        $this->limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
    }

    // -------------------------------------------------------------------------
    // isLimited() — invalid inputs
    // -------------------------------------------------------------------------

    public function testIsLimitedEmptyKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimited('', 5, 60);
    }

    public function testIsLimitedZeroTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimited('key', 5, 0);
    }

    public function testIsLimitedNegativeTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimited('key', 5, -1);
    }

    // -------------------------------------------------------------------------
    // isLimitedWithBan() — invalid inputs
    // -------------------------------------------------------------------------

    /**
     * $ttl is validated first inside isLimitedWithBan before touching the cache.
     */
    public function testIsLimitedWithBanZeroTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 0, 3, 300, 3600, null);
    }

    public function testIsLimitedWithBanNegativeTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, -10, 3, 300, 3600, null);
    }

    /**
     * $banTtl is the first parameter validated inside isLimitedWithBan.
     */
    public function testIsLimitedWithBanZeroBanTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, 3, 300, 0, null);
    }

    public function testIsLimitedWithBanNegativeBanTtlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, 3, 300, -1, null);
    }

    public function testIsLimitedWithBanZeroBanTimeFrameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, 3, 0, 3600, null);
    }

    public function testIsLimitedWithBanNegativeBanTimeFrameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, 3, -1, 3600, null);
    }

    /**
     * Empty key is validated inside isLimited(), which is called by isLimitedWithBan().
     * The cache may be touched before the exception is thrown (violation count fetch),
     * but the exception is still raised correctly.
     */
    public function testIsLimitedWithBanEmptyKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('', 5, 60, 3, 300, 3600, null);
    }

    // -------------------------------------------------------------------------
    // clearRateLimitedKey() — invalid inputs and edge cases
    // -------------------------------------------------------------------------

    public function testClearRateLimitedKeyEmptyKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->clearRateLimitedKey('');
    }

    /**
     * Deleting a key that does not exist must return false gracefully, not throw.
     */
    public function testClearNonExistentKeyReturnsFalse(): void
    {
        $result = $this->limiter->clearRateLimitedKey('non_existent_key_'.microtime(true));
        $this->assertFalse($result);
    }
}
