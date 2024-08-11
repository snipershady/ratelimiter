<?php

namespace RateLimiter\Service;

use Predis\Client;

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
 * Description of RateLimiterServiceAPCuSlidingWindow
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceAPCuSlidingWindow extends AbstractRateLimiterService {

    /**
     * {@inheritDoc}
     */
    public function isLimited(string $key, int $limit, int $ttl): bool {
        $now = microtime(true) * 1000; // Timestamp in millisecondi
        // Recupera il set di timestamp dalla cache
        $timestamps = apcu_fetch($key);
        if ($timestamps === false) {
            $timestamps = [];
        }

        // Aggiungi il timestamp corrente
        $timestamps[] = $now;

        // Rimuovi i timestamp più vecchi del TTL
        $timestamps = array_filter($timestamps, function ($timestamp) use ($now, $ttl) {
            return $timestamp >= ($now - $ttl);
        });

        // Conta il numero di timestamp rimanenti
        $count = count($timestamps);

        // Memorizza nuovamente il set aggiornato nella cache
        apcu_store($key, $timestamps, $ttl / 1000); // TTL in secondi

        return $count > $limit;
    }

    /**
     * {@inheritDoc}
     */
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool {
        $this->checkTTL($banTtl);
        $this->checkTimeFrame($banTimeFrame);
        $violationCountKey = "BAN_violation_count" . $key . $clientIp;
        $needBan = (int) apcu_fetch($violationCountKey);

        if ($needBan >= $maxAttempts) {
            $ttl = $banTtl;
        }
        $actual = $this->isLimited($key, $limit, $ttl);
        if ($actual) {
            $step = 1;
            $success = null;
            apcu_inc($violationCountKey, $step, $success, $banTtl);
        }
        return $actual;
    }

    /**
     * {@inheritDoc}
     */
    public function clearRateLimitedKey(string $key): bool {
        $this->checkKey($key);
        return (bool) $this->redis->del($key);
    }
}
