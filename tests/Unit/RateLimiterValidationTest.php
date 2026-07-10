<?php

namespace RateLimiter\Tests\Unit;

use RateLimiter\Enum\CacheEnum;
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
 * Tests for input validation across all public methods.
 * Validation logic lives in AbstractRateLimiterService and is shared by all backends,
 * so APCu is used as the test backend for simplicity.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @example ./vendor/bin/phpunit tests/Unit/RateLimiterValidationTest.php
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

    /**
     * empty('0') === true in PHP, so a naive `empty($key)` check would wrongly
     * reject the legitimate numeric-string key "0" (e.g. a userId cast to string).
     */
    public function testIsLimitedKeyOfLiteralZeroIsAccepted(): void
    {
        $result = $this->limiter->isLimited('0', 5, 60);
        $this->assertFalse($result);
    }

    public function testIsLimitedOverlongKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimited(str_repeat('a', 129), 5, 60);
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
     * $key is validated first, before any cache access, by the shared
     * isLimitedWithBan() template in AbstractRateLimiterService.
     */
    public function testIsLimitedWithBanEmptyKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('', 5, 60, 3, 300, 3600, null);
    }

    public function testIsLimitedWithBanOverlongClientIpThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 46 bytes: one over the 45-byte cap (the longest possible IPv6 literal).
        $this->limiter->isLimitedWithBan('key', 5, 60, 3, 300, 3600, str_repeat('1', 46));
    }

    /**
     * maxAttempts <= 0 would otherwise make "violation count >= maxAttempts"
     * true from the very first request, applying $banTtl unconditionally.
     */
    public function testIsLimitedWithBanZeroMaxAttemptsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, 0, 300, 3600, null);
    }

    public function testIsLimitedWithBanNegativeMaxAttemptsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->isLimitedWithBan('key', 5, 60, -3, 300, 3600, null);
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
        $result = $this->limiter->clearRateLimitedKey('non_existent_key_' . microtime(true));
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // clearBan() — invalid inputs and edge cases
    // -------------------------------------------------------------------------

    public function testClearBanEmptyKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->clearBan('');
    }

    public function testClearBanOverlongClientIpThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->clearBan('key', str_repeat('1', 46));
    }

    /**
     * Clearing a ban that doesn't exist must return false gracefully, not throw.
     */
    public function testClearBanNonExistentKeyReturnsFalse(): void
    {
        $result = $this->limiter->clearBan('non_existent_key_' . microtime(true));
        $this->assertFalse($result);
    }
}
