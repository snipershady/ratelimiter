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
 * Description of RatelimiterService.
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
        $success = null;

        $actual = $this->getActual($key, $step, $success, $ttl);

        return $actual > $limit;
    }

    #[\Override]
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
    {
        $this->checkTTL($banTtl);
        $this->checkTimeFrame($banTimeFrame);
        if (null !== $clientIp) {
            $violationCountKey = 'BAN_violation_count'.$key.$clientIp;
        } else {
            $violationCountKey = 'BAN_violation_count'.$key;
        }

        $needBan = (int) apcu_fetch($violationCountKey);

        if ($needBan >= $maxAttempts) {
            $ttl = $banTtl;
        }
        $actual = $this->isLimited($key, $limit, $ttl);
        if ($actual) {
            $step = 1;
            $success = null;
            $actual = $this->getActual($violationCountKey, $step, $success, $banTtl);
        }

        return (int) $actual > 0;
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return apcu_delete($key);
    }

    /**
     * Serve un retry loop sul CAS fino a successo (pattern standard per operazioni lock-free):
     */
    private function getActual(string $key, int $step, ?bool $success, int $ttl): int
    {
        if (empty(apcu_exists($key))) {
            $actual = apcu_inc($key, $step, $success, $ttl);
        } else {
            do {
                $current = (int) apcu_fetch($key);
                $actual = $current + 1;
            } while (!apcu_cas($key, $current, $actual));
        }

        return $actual;
    }
}
