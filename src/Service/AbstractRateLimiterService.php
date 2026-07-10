<?php

declare(strict_types=1);

namespace RateLimiter\Service;

use Predis\Client;
use RateLimiter\Adapter\PhpRedisAdapter;
use RateLimiter\Adapter\PredisAdapter;
use RateLimiter\Enum\CacheEnum;

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
 * Base class for all rate limiter backends. Provides shared input-validation
 * helpers and the backward-compatible factory() entry point.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractRateLimiterService implements RateLimiterInterface
{
    /**
     * Bounds $key so that the composed internal ban-tracking key
     * ("BAN_violation_count_" + key + "_" + clientIp, see buildViolationCountKey())
     * stays safely under Memcached's hard 250-byte key limit, and guards every
     * backend against unbounded key-space growth from a caller-controlled $key.
     */
    private const int MAX_KEY_LENGTH = 128;

    /**
     * Generous enough for any IPv6 literal (45 bytes covers the longest form,
     * including an IPv4-mapped suffix), while still rejecting a caller who
     * mistakenly passes an unbounded string (e.g. a raw X-Forwarded-For
     * header) as $clientIp.
     */
    private const int MAX_CLIENT_IP_LENGTH = 45;

    /**
     * @param string $key   <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int    $limit <p>Limit</p>
     * @param int    $ttl   <p>Timeframe</p>
     */
    #[\Override]
    abstract public function isLimited(string $key, int $limit, int $ttl): bool;

    /**
     * <p>Context: It is necessary to limit access to a specific function, but you are receiving more requests than you'd want to accept from the same client, so you want to punish the client more than the other.
     *
     * </p>
     *
     * Shared template for all backends: validates input, applies $banTtl once
     * the violation count reaches $maxAttempts, delegates the actual limiting
     * to isLimited(), and records a violation when the request is limited.
     * Backends only implement how to read and atomically bump the violation
     * counter (getViolationCount() / recordViolation()) — this keeps the
     * ban/violation algorithm itself in one place instead of hand-duplicated
     * per backend, where a fix applied to one (like the atomic-TTL handling
     * in recordViolation()) could otherwise be missed in the others.
     *
     * Known limitation: the violation-count read below and the atomic
     * increment in recordViolation() are not a single atomic operation, since
     * whether this request counts as a violation is only known after
     * isLimited() runs. Under concurrent requests that arrive while the
     * counter sits exactly at $maxAttempts - 1, more than one of them can
     * read the same pre-increment value and each proceed under the normal
     * $ttl instead of exactly one crossing into $banTtl. The counter itself
     * is always correct (each backend increments it atomically); only the
     * ttl choice for the request(s) racing the exact crossing moment can be
     * off by one window. This does not allow bypassing the ban indefinitely
     * — the very next request reliably observes the post-crossing count.
     *
     * @param string      $key          <p>Name of the function you want to limit. You can use __FUNCTION__ or __METHOD__ inside a subroutine to avoid collision</p>
     * @param int         $limit        <p>Limit</p>
     * @param int         $ttl          <p>Timeframe</p>
     * @param int         $maxAttempts  <p>Maximum attempts before a client will receive a ban</p>
     * @param int         $banTimeFrame <p>Timeframe during a client cannot be limited more than the max attempts number</p>
     * @param int         $banTtl       <p>New timeframe for banished client</p>
     * @param string|null $clientIp     <p>Useful to ban a specific client from a function</p>
     */
    #[\Override]
    public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);
        $this->checkMaxAttempts($maxAttempts);
        $this->checkTTL($banTtl);
        $this->checkTTL($ttl);
        $this->checkTimeFrame($banTimeFrame);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        if ($this->getViolationCount($violationCountKey) >= $maxAttempts) {
            $ttl = $banTtl;
        }

        $actual = $this->isLimited($key, $limit, $ttl);

        if ($actual) {
            // Return value intentionally unused here: it reflects the
            // atomically-updated violation count, but this request's own ttl
            // decision was already made above from the pre-increment read
            // (see the class-level note on this method). It exists so
            // implementations don't discard a value they already computed,
            // and so tests can assert on it directly instead of racing a
            // separate read against concurrent writers.
            $this->recordViolation($violationCountKey, $banTimeFrame);
        }

        return $actual;
    }

    /**
     * <p>Returns the current value of the ban violation counter at $violationCountKey,
     * or 0 if it doesn't exist yet.</p>.
     */
    abstract protected function getViolationCount(string $violationCountKey): int;

    /**
     * <p>Atomically increments the ban violation counter at $violationCountKey
     * and returns its new value. Implementations must bind it to a
     * $banTimeFrame-second window on the first violation only — the window is
     * fixed from the first offence and must never be renewed by later ones.</p>.
     *
     * <p>The returned count is the true, atomically-observed value at the
     * moment of increment (unlike getViolationCount(), which is a plain read
     * and can be stale under concurrency — see the note on
     * isLimitedWithBan()). Callers must not discard it if they need an
     * accurate reading of whether this exact call crossed the ban
     * threshold.</p>
     */
    abstract protected function recordViolation(string $violationCountKey, int $banTimeFrame): int;

    /**
     * <p>Delete the limited key</p>.
     *
     * @param string $key <p>key to set free from limiter</p>
     */
    #[\Override]
    abstract public function clearRateLimitedKey(string $key): bool;

    /**
     * <p>Clear an active ban: deletes both the request counter and the ban
     * violation counter for $key, so the next request is treated as the
     * first in a fresh window instead of immediately re-triggering the ban.</p>.
     *
     * @param string      $key      <p>key to unban</p>
     * @param string|null $clientIp <p>must match the value passed to isLimitedWithBan(), if any</p>
     */
    #[\Override]
    abstract public function clearBan(string $key, ?string $clientIp = null): bool;

    /**
     * <p>Builds the internal cache key used to track ban violations for $key,
     * optionally scoped to $clientIp so each IP gets its own counter. Mirrors
     * the key naming used by isLimitedWithBan() in each backend.</p>.
     */
    protected function buildViolationCountKey(string $key, ?string $clientIp): string
    {
        return null !== $clientIp
            ? 'BAN_violation_count_' . $key . '_' . $clientIp
            : 'BAN_violation_count_' . $key;
    }

    /**
     * <p>Verify if <b>ttl</b> parameter is positive integer. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkTTL(int $ttl): void
    {
        if (!$this->isPositiveInteger($ttl)) {
            throw new \InvalidArgumentException(sprintf('TTL must be a positive integer, %d given', $ttl));
        }
    }

    /**
     * <p>Verify if <b>$timeFrame</b> parameter is positive integer. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkTimeFrame(int $timeFrame): void
    {
        if (!$this->isPositiveInteger($timeFrame)) {
            throw new \InvalidArgumentException(sprintf('TimeFrame must be a positive integer, %d given', $timeFrame));
        }
    }

    /**
     * <p>Verify if <b>$maxAttempts</b> parameter is positive integer. Throws InvalidArgumentException</p>.
     *
     * A non-positive value would make the "violation count >= maxAttempts" check
     * true from the very first request, applying $banTtl unconditionally instead
     * of after genuine repeated offences.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkMaxAttempts(int $maxAttempts): void
    {
        if (!$this->isPositiveInteger($maxAttempts)) {
            throw new \InvalidArgumentException(sprintf('MaxAttempts must be a positive integer, %d given', $maxAttempts));
        }
    }

    /**
     * <p>Verify if <b>key</b> parameter is not empty and not unreasonably long. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkKey(string $key): void
    {
        if ('' === $key) {
            throw new \InvalidArgumentException(sprintf('Key cannot be empty, %s given, instead', $key));
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Key must be at most %d bytes, %d given', self::MAX_KEY_LENGTH, strlen($key)));
        }
    }

    /**
     * <p>Verify that <b>$clientIp</b>, when provided, is not unreasonably long. Throws InvalidArgumentException</p>.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkClientIp(?string $clientIp): void
    {
        if (null !== $clientIp && strlen($clientIp) > self::MAX_CLIENT_IP_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Client IP must be at most %d bytes, %d given', self::MAX_CLIENT_IP_LENGTH, strlen($clientIp)));
        }
    }

    public static function factory(CacheEnum $cacheEnum, Client|\Redis|\Memcached|null $client = null): AbstractRateLimiterService
    {
        return match ($cacheEnum) {
            CacheEnum::APCU => new RateLimiterServiceAPCu(),
            CacheEnum::REDIS => new RateLimiterServiceRedis(
                new PredisAdapter($client instanceof Client ? $client : throw new \InvalidArgumentException('Predis\Client required for REDIS backend'))
            ),
            CacheEnum::PHP_REDIS => new RateLimiterServiceRedis(
                new PhpRedisAdapter($client instanceof \Redis ? $client : throw new \InvalidArgumentException('\Redis instance required for PHP_REDIS backend'))
            ),
            CacheEnum::MEMCACHED => new RateLimiterServiceMemcached(
                $client instanceof \Memcached ? $client : throw new \InvalidArgumentException('\Memcached instance required for MEMCACHED backend')
            ),
        };
    }

    private function isPositiveInteger(int $value): bool
    {
        return $value > 0;
    }
}
