<?php

declare(strict_types=1);

namespace RateLimiter\Service;

interface RateLimiterInterface
{
    public function isLimited(string $key, int $limit, int $ttl): bool;

    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool;

    public function clearRateLimitedKey(string $key): bool;

    /**
     * Clears both the request counter and the ban violation counter for $key,
     * so a client banned via isLimitedWithBan() is immediately unbanned.
     * $clientIp must match the value passed to isLimitedWithBan() to target
     * the correct per-IP violation counter (or null for the shared counter).
     */
    public function clearBan(string $key, ?string $clientIp = null): bool;
}
