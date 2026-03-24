<?php

namespace RateLimiter\Service;

interface RateLimiterInterface
{

    public function isLimited(string $key, int $limit, int $ttl): bool;

    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool;

    public function clearRateLimitedKey(string $key): bool;
}
