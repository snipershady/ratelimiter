<?php

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
 * APCu-backed rate limiter. Uses lock-free CAS loops to safely increment
 * shared counters without requiring a mutex or external locking mechanism.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceAPCu extends AbstractRateLimiterService
{
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
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
    {
        $this->checkTTL($banTtl);
        $this->checkTTL($ttl);
        $this->checkTimeFrame($banTimeFrame);
        $violationCountKey = null !== $clientIp ? 'BAN_violation_count_'.$key.'_'.$clientIp : 'BAN_violation_count_'.$key;
        $needBan = (int) apcu_fetch($violationCountKey);
        $actual = 0;
        if ($needBan >= $maxAttempts) {
            $ttl = $banTtl;
        }
        $isLImited = $this->isLimited($key, $limit, $ttl);
        if ($isLImited) {
            $step = 1;
            // TTL = $banTimeFrame: the violation counter expires $banTimeFrame seconds after
            // the FIRST violation. apcu_inc() only sets the TTL at key creation (fixed window).
            $actual = $this->getActual($violationCountKey, $step, $banTimeFrame);
        }

        return $actual > 0;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return apcu_delete($key);
    }

    /**
     * Atomically increments a counter using a CAS retry loop (standard lock-free pattern).
     * On first access the key is created with the given TTL via apcu_inc(); subsequent
     * accesses use apcu_cas() to avoid lost updates under concurrent requests.
     */
    private function getActual(string $key, int $step, int $ttl): int
    {
        $success = null;
        if (!apcu_exists($key)) {
            do {
                $actual = (int) apcu_inc($key, $step, $success, $ttl);
            } while (!$success);
        } else {
            do {
                $current = (int) apcu_fetch($key);
                $actual = $current + 1;
            } while (!apcu_cas($key, $current, $actual));
        }

        return $actual;
    }
}
