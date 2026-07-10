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
 * Sliding window counter: a lightweight, O(1)-memory approximation of the
 * sliding-window-log algorithm, used by the Memcached and APCu backends
 * (see AlgorithmEnum::SLIDING_WINDOW — neither backend can maintain an
 * unbounded per-request log atomically). Centralizes the bucket math once so
 * it cannot drift between backends; each concrete backend only supplies four
 * small primitives it already needs for its fixed-window counterpart:
 * atomic increment-with-TTL-on-create, plain read, plain write, and delete.
 *
 * Algorithm: time is divided into fixed $ttl-second buckets identified by
 * floor(now / $ttl) — a pure function of wall-clock time, so every
 * concurrent request independently computes the same bucket key for the
 * same instant with no coordination step (unlike a "detect rollover and
 * rotate" scheme, which would need one, reopening the exact CAS-loop hazard
 * this design avoids). Each request atomically bumps the counter for the
 * CURRENT bucket, and reads (without incrementing) the counter for the
 * PREVIOUS bucket. The two are combined with a linear weight equal to how
 * much of the previous bucket's span still overlaps the trailing
 * $ttl-second window from now:
 *
 *   weightedCount = previousCount * (1 - elapsedFractionOfCurrentBucket) + currentCount
 *
 * This smooths the fixed window's boundary-burst problem (up to 2x $limit
 * requests passing across a single window boundary) down to a small,
 * bounded overcount, without ever storing more than two integers per key.
 *
 * The exact same {@see incrementBucket()}/{@see getBucketCount()} primitives
 * also implement the ban-violation counter, which intentionally stays
 * fixed-window (see the class-level note on
 * AbstractRateLimiterService::isLimitedWithBan()): getViolationCount() is
 * just a plain bucket read, recordViolation() is just a bucket increment —
 * both single, un-bucketed keys, exactly like the fixed-window backends.
 *
 * clearRateLimitedKey()/clearBan() need to know which bucket(s) belong to a
 * key, but $ttl — the bucket size — is only ever passed to isLimited(), not
 * to these two administrative methods (by design: RateLimiterInterface's
 * four method signatures never change based on algorithm or backend). To
 * resolve this without widening the public contract, isLimited() also writes
 * a small metadata entry recording the $ttl it was last called with for that
 * key (with a generous TTL of its own, so it outlives both buckets it
 * describes); clearRateLimitedKey()/clearBan() read it back to locate the
 * current and previous bucket. A key that was never rate-limited has no
 * metadata, so clearing it correctly reports "nothing to clear".
 *
 * Known limitation, shared in spirit with the "ban is not retroactive
 * mid-window" note on AbstractRateLimiterService::isLimitedWithBan(): bucket
 * keys are namespaced by $ttl (floor(now / $ttl)), so calling isLimited() for
 * the same $key with a *different* $ttl than before — exactly what
 * isLimitedWithBan() does when it swaps in $banTtl — starts counting in a
 * fresh namespace rather than rewriting the old one in place. The old
 * namespace's buckets are left to expire on their own (bounded by their own
 * $ttl * 2 storage lifetime) rather than being eagerly deleted on every
 * mismatch: eagerly deleting them would also wipe out a still-active ban
 * bucket on any call where the violation counter happens to have already
 * expired while $banTtl has not (a common, intentional configuration — see
 * the README's own $banTimeFrame=300 / $banTtl=3600 example), which would
 * prematurely end the ban. In practice this means a $key switching between
 * two $ttl values in quick succession may see a small, temporary residual
 * count from the abandoned namespace for up to that namespace's own
 * $ttl * 2 seconds — never longer, and never a permanent leak.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractSlidingWindowCounterService extends AbstractRateLimiterService
{
    /**
     * Suffix for the metadata key that records the last $ttl a key was
     * bucketed with — see the class-level note above.
     */
    private const string BUCKET_TTL_SUFFIX = ':bucket_ttl';

    /**
     * Metadata TTL is a multiple of the bucket $ttl so it always outlives
     * both the current and previous bucket, which can each live up to
     * $ttl * 2 seconds (see incrementBucket()'s call in isLimited()).
     */
    private const int BUCKET_TTL_METADATA_MULTIPLIER = 3;

    /**
     * Atomically increments the counter at $bucketKey, creating it with TTL
     * $ttl if it doesn't exist yet. Same contract as each backend's existing
     * fixed-window increment primitive.
     */
    abstract protected function incrementBucket(string $bucketKey, int $ttl): int;

    /**
     * Plain read of the counter at $bucketKey, or 0 if it doesn't exist.
     * Never increments, never creates the key.
     */
    abstract protected function getBucketCount(string $bucketKey): int;

    /**
     * Unconditionally (over)writes $key with $value, applying TTL $ttl.
     * Used only for the bucket-ttl metadata entry — never needs to be atomic
     * with a concurrent read/write of the same key, since it merely records
     * "the most recent $ttl this key was used with".
     */
    abstract protected function setKey(string $key, int $value, int $ttl): void;

    /**
     * Deletes $key. Returns true if a key was actually removed.
     */
    abstract protected function deleteKey(string $key): bool;

    #[\Override]
    public function isLimited(string $key, int $limit, int $ttl): bool
    {
        $this->checkKey($key);
        $this->checkTTL($ttl);

        $now = time();
        $bucketIndex = intdiv($now, $ttl);
        $elapsedFraction = ($now % $ttl) / $ttl;

        $this->setKey($this->bucketTtlMetaKey($key), $ttl, $ttl * self::BUCKET_TTL_METADATA_MULTIPLIER);

        // Bucket TTL is ttl*2: the previous bucket must still be readable
        // for the whole lifetime of the current one before it's no longer needed.
        $currentCount = $this->incrementBucket($this->bucketKey($key, $ttl, $bucketIndex), $ttl * 2);
        $previousCount = $this->getBucketCount($this->bucketKey($key, $ttl, $bucketIndex - 1));

        $weightedCount = $previousCount * (1 - $elapsedFraction) + $currentCount;

        return $weightedCount > $limit;
    }

    #[\Override]
    protected function getViolationCount(string $violationCountKey): int
    {
        return $this->getBucketCount($violationCountKey);
    }

    /**
     * {@inheritDoc}
     *
     * The violation counter is intentionally NOT bucketed like the main
     * counter: it uses the plain fixed-window primitive directly, since the
     * ban observation window is fixed-window by design (see the class-level
     * note on AbstractRateLimiterService::isLimitedWithBan()).
     */
    #[\Override]
    protected function recordViolation(string $violationCountKey, int $banTimeFrame): int
    {
        return $this->incrementBucket($violationCountKey, $banTimeFrame);
    }

    /**
     * {@inheritDoc}
     *
     * Deletes both the current and previous bucket for $key, located via the
     * bucket-ttl metadata isLimited() maintains — deleting only the current
     * bucket would leave a stale, decaying contribution from the previous
     * one instead of genuinely resetting the key. Returns false if $key was
     * never rate-limited (no metadata to locate its buckets from).
     */
    #[\Override]
    public function clearRateLimitedKey(string $key): bool
    {
        $this->checkKey($key);

        return $this->clearBuckets($key);
    }

    #[\Override]
    public function clearBan(string $key, ?string $clientIp = null): bool
    {
        $this->checkKey($key);
        $this->checkClientIp($clientIp);

        $violationCountKey = $this->buildViolationCountKey($key, $clientIp);

        $mainCleared = $this->clearBuckets($key);
        $violationCleared = $this->deleteKey($violationCountKey);

        return $mainCleared || $violationCleared;
    }

    private function clearBuckets(string $key): bool
    {
        $ttl = $this->readBucketTtl($key);

        if (null === $ttl) {
            return false;
        }

        return $this->clearBucketsForTtl($key, $ttl);
    }

    private function clearBucketsForTtl(string $key, int $ttl): bool
    {
        $bucketIndex = intdiv(time(), $ttl);
        $deletedCurrent = $this->deleteKey($this->bucketKey($key, $ttl, $bucketIndex));
        $deletedPrevious = $this->deleteKey($this->bucketKey($key, $ttl, $bucketIndex - 1));

        return $deletedCurrent || $deletedPrevious;
    }

    /**
     * A stored ttl is always a positive integer (enforced by checkTTL() in
     * isLimited()), so a read of 0 unambiguously means "no metadata" —
     * either $key was never rate-limited, or its metadata has since expired
     * naturally (which only happens well after both of its buckets already
     * have too, since the metadata TTL outlives them).
     */
    private function readBucketTtl(string $key): ?int
    {
        $ttl = $this->getBucketCount($this->bucketTtlMetaKey($key));

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * $ttl is embedded in the bucket key (not just $bucketIndex) so that two
     * different $ttl values used for the same $key — e.g. isLimitedWithBan()
     * swapping in $banTtl — can never coincidentally collide on the same
     * physical bucket merely because floor(now / ttlA) == floor(now / ttlB)
     * for some particular instant.
     */
    private function bucketKey(string $key, int $ttl, int $bucketIndex): string
    {
        return $key . ':' . $ttl . ':' . $bucketIndex;
    }

    private function bucketTtlMetaKey(string $key): string
    {
        return $key . self::BUCKET_TTL_SUFFIX;
    }
}
