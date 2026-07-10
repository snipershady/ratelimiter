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
 * Memcached-backed rate limiter using the php-memcached native extension.
 *
 * TTL semantics: Memcached's increment() never resets a key's expiry, so the
 * window is always fixed from the moment the key is first created. This gives
 * the same fixed-window behaviour as the APCu backend.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceMemcached extends AbstractRateLimiterService
{
    /**
     * Memcached's protocol treats an exptime greater than 30 days (2,592,000
     * seconds) as an absolute Unix timestamp rather than a relative offset.
     *
     * @see https://github.com/memcached/memcached/wiki/Programming#expiration
     */
    private const int MAX_RELATIVE_TTL = 2_592_000;

    public function __construct(private readonly \Memcached $client)
    {
    }

    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        return $this->atomicIncrement($key, $ttl) > $limit;
    }

    #[\Override]
    protected function getViolationCount(string $violationCountKey): int
    {
        return (int) $this->client->get($violationCountKey);
    }

    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): void
    {
        // TTL = $banTimeFrame: the violation counter expires $banTimeFrame seconds after
        // the FIRST violation. atomicIncrement() only sets TTL at key creation (fixed window).
        $this->atomicIncrement($violationCountKey, $banTimeFrame);
    }

    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return $this->client->delete($key);
    }

    #[\Override]
    public function clearBan(string $key, ?string $clientIp = null): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        $mainCleared = $this->client->delete($key);
        $violationCleared = $this->client->delete($violationCountKey);

        return $mainCleared || $violationCleared;
    }

    /**
     * Atomically increments a counter, creating it with the given TTL if it does not exist.
     *
     * Sequence (compatible with both ASCII and binary Memcached protocol):
     *   1. increment() — succeeds if the key already exists.
     *   2. On miss, add() with value 1 and the desired TTL — atomically creates the key
     *      if no concurrent writer has done so between step 1 and now.
     *   3. If add() loses the race (another process created the key first), retry increment().
     *
     * increment() never resets the key TTL, so the expiry window is fixed from creation.
     *
     * Each step distinguishes an expected outcome (key missing, or lost the add() race)
     * from a genuine backend failure via getResultCode(). A rate limiter is a security
     * control: on a real Memcached error we must fail closed (throw) rather than fall
     * back to treating the request as "first ever" and letting it through unlimited.
     */
    private function atomicIncrement(string $key, int $ttl): int
    {
        $count = $this->client->increment($key);

        if (false !== $count) {
            return (int) $count;
        }

        if (\Memcached::RES_NOTFOUND !== $this->client->getResultCode()) {
            throw $this->memcachedFailure('increment', $key);
        }

        // Key does not exist: create it atomically with value 1.
        if ($this->client->add($key, 1, $this->normalizeTtl($ttl))) {
            return 1;
        }

        if (\Memcached::RES_NOTSTORED !== $this->client->getResultCode()) {
            throw $this->memcachedFailure('add', $key);
        }

        // Another process created the key between our increment() miss and add(); retry.
        $count = $this->client->increment($key);

        if (false === $count) {
            throw $this->memcachedFailure('increment', $key);
        }

        return (int) $count;
    }

    /**
     * Converts a relative TTL above Memcached's 30-day threshold into an
     * absolute Unix timestamp, so callers can keep passing a plain "seconds
     * from now" value (as required by isLimited()/isLimitedWithBan()'s
     * contract) regardless of magnitude, instead of it being silently
     * reinterpreted by Memcached as a moment already in the past.
     */
    private function normalizeTtl(int $ttl): int
    {
        return $ttl > self::MAX_RELATIVE_TTL ? time() + $ttl : $ttl;
    }

    private function memcachedFailure(string $operation, string $key): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Memcached %s() failed for key "%s": %s (code %d)',
            $operation,
            $key,
            $this->client->getResultMessage(),
            $this->client->getResultCode(),
        ));
    }
}
