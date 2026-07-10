<?php

declare(strict_types=1);

namespace RateLimiter\Service;

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
 * APCu-backed rate limiter. Relies on apcu_inc()'s built-in atomicity to
 * safely increment shared counters without requiring a mutex or external
 * locking mechanism.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceAPCu extends AbstractRateLimiterService
{
    use ApcuAtomicCounterTrait;

    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);
        $step = 1;

        $actual = $this->getActual($key, $step, $ttl);

        return $actual > $limit;
    }

    #[\Override]
    protected function getViolationCount(string $violationCountKey): int
    {
        return (int) apcu_fetch($violationCountKey);
    }

    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): int
    {
        // TTL = $banTimeFrame: the violation counter expires $banTimeFrame seconds after
        // the FIRST violation. apcu_inc() only sets the TTL at key creation (fixed window).
        return $this->getActual($violationCountKey, 1, $banTimeFrame);
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return apcu_delete($key);
    }

    #[\Override]
    public function clearBan(string $key, ?string $clientIp = null): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        $mainCleared = apcu_delete($key);
        $violationCleared = apcu_delete($violationCountKey);

        return $mainCleared || $violationCleared;
    }
}
