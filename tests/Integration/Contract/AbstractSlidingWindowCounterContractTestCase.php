<?php

declare(strict_types=1);

namespace RateLimiter\Tests\Integration\Contract;

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
 * Shared contract for the two sliding-window-counter backends (Memcached and
 * APCu — see AbstractSlidingWindowCounterService). Ban-lifecycle behaviour
 * uses the same plain fixed-window violation counter on both, so those
 * assertions are shared here verbatim instead of duplicated per backend,
 * mirroring AbstractRedisFamilyContractTestCase's role for the two Redis
 * backends.
 *
 * testTtlExpiration() is overridden rather than inherited as-is from
 * AbstractRateLimiterContractTestCase: that parent version sleeps exactly
 * $ttl+1 seconds and expects a hard reset, which is a fixed-window-specific
 * assumption. A sliding-window counter deliberately keeps a fading, bounded
 * contribution from the immediately-preceding bucket after crossing a single
 * boundary (that smoothing is the entire point of the feature — see
 * testBoundaryIsSmoothedNotHardReset() below) and only guarantees a clean
 * reset once a full two bucket-widths have elapsed.
 *
 * Every test below that cares about exactly where a boundary falls calls
 * {@see alignToBucketBoundary()} first, so its outcome is a deterministic
 * function of the chosen sleep()s instead of depending on where in its own
 * bucket the test happened to start relative to wall-clock time.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract class AbstractSlidingWindowCounterContractTestCase extends AbstractRateLimiterContractTestCase
{
    /**
     * Sleeps until time() is an exact multiple of $ttl, so every subsequent
     * isLimited() call in the test starts from a known, controlled position
     * within its bucket (elapsedFraction ~ 0) instead of an arbitrary one.
     */
    private function alignToBucketBoundary(int $ttl): void
    {
        $remainder = time() % $ttl;

        if (0 !== $remainder) {
            sleep($ttl - $remainder);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Same assertions as the parent version, but sleeps 2*ttl+1 seconds
     * instead of ttl+1: after two full bucket widths, the bucket that was
     * active during the first half of the test is no longer "previous" for
     * the new current bucket (it's two indices behind), so its contribution
     * is guaranteed to be zero regardless of exactly where in its own bucket
     * the test happened to start — unlike sleeping only ttl+1, whose outcome
     * would otherwise depend on wall-clock phase alignment.
     */
    #[\Override]
    public function testTtlExpiration(): void
    {
        $limit = 2;
        $ttl = 3;
        $key = $this->uniqueKey();
        $limiter = $this->makeLimiter();

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached

        sleep((2 * $ttl) + 1);

        $this->assertFalse($limiter->isLimited($key, $limit, $ttl)); // new window, no residual weight
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        $this->assertTrue($limiter->isLimited($key, $limit, $ttl)); // limit reached again
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    /**
     * The defining behavioural difference from the fixed window: right after
     * crossing exactly one bucket boundary, a client that was previously at
     * the limit is still partially restricted by the decaying weight of the
     * old bucket, instead of getting a completely clean slate. This is what
     * eliminates the fixed window's "2x $limit at the boundary" burst.
     */
    public function testBoundaryIsSmoothedNotHardReset(): void
    {
        $limit = 3;
        $ttl = 3;
        $key = $this->uniqueKey();
        $limiter = $this->makeLimiter();

        $this->alignToBucketBoundary($ttl);

        // Fill the current bucket right up to the limit.
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));
        $this->assertFalse($limiter->isLimited($key, $limit, $ttl));

        sleep($ttl); // land right at the start of the next bucket: elapsedFraction ~ 0

        // A fixed window would allow $limit more requests here. The sliding
        // counter instead still carries almost the full weight of the
        // previous (filled) bucket: weighted ~= 3*(1-~0) + 1 = ~4 > 3.
        $this->assertTrue($limiter->isLimited($key, $limit, $ttl));
    }

    // -------------------------------------------------------------------------
    // isLimitedWithBan() — basic lifecycle (identical across both backends,
    // since the violation counter is a plain fixed-window key on both).
    // -------------------------------------------------------------------------

    public function testRateLimitWithBan(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey();
        $limit = 1;
        $maxAttempts = 3;
        $ttl = 2;
        $banTimeFrame = 10;
        $banTtl = 6;
        $clientIp = null;

        $this->alignToBucketBoundary($ttl);

        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)); // first request: not limited
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 1
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 2
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));  // violation_count = 3 = maxAttempts

        sleep(2 * $ttl); // the normal-window bucket fully decays (guaranteed clean, see testTtlExpiration)

        // violation_count >= maxAttempts: ttl escalates to $banTtl. First
        // request lands in a brand-new $banTtl bucket: not limited.
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
        // Second request in the same still-fresh $banTtl bucket: limited.
        $this->assertTrue($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));

        sleep(2 * $banTtl); // ban bucket fully decays AND violation_count (banTimeFrame=10) expires

        // Reverts to the normal $ttl namespace, long after both the ban
        // bucket and violation_count have naturally expired: a clean slate.
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
    }

    /**
     * clearBan() must delete both the (banTtl-namespaced) main bucket that
     * is actively enforcing the ban and the violation counter, using the
     * bucket-ttl metadata isLimited() maintains to locate the former.
     */
    public function testClearBanActuallyUnbansClient(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('clearban');
        $limit = 1;
        $maxAttempts = 2;
        $ttl = 2;
        $banTimeFrame = 30;
        $banTtl = 10;
        $clientIp = '203.0.113.9';

        $this->alignToBucketBoundary($ttl);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // not limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep(2 * $ttl); // normal-window bucket fully decays

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // not limited (fresh bucket)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2 = maxAttempts

        // ban window opens: fresh $banTtl bucket, first request free
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));

        $this->assertTrue($limiter->clearBan($key, $clientIp));

        // clearBan() only clears the ban ($banTtl) bucket it can locate via
        // the metadata isLimited() last wrote — the *pre-ban* normal-$ttl
        // bucket from the two calls above is a different, older namespace
        // entry it never touches (see the class-level note on
        // AbstractSlidingWindowCounterService). Give it time to naturally
        // expire before checking, otherwise this assertion would be testing
        // the documented bounded-residual behaviour, not a genuine unban.
        sleep((2 * $ttl) + 1);

        // Genuinely unbanned: the ban bucket, violation_count, and the old
        // pre-ban normal bucket have all expired, so this lands on a fresh
        // normal-$ttl bucket.
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
    }

    /**
     * Reverting from $banTtl back to the normal $ttl lands in a different
     * bucket namespace (see the class-level note on
     * AbstractSlidingWindowCounterService for why old-namespace buckets are
     * left to expire naturally rather than eagerly deleted on every switch).
     * As long as the old namespace's buckets have had time to naturally
     * expire before the revert — true in any realistic deployment, where
     * $banTtl is normally much larger than $ttl — the revert lands on a
     * genuinely clean slate rather than a leaked, contaminated one.
     */
    public function testNormalWindowIsCleanAfterBanNamespaceNaturallyExpires(): void
    {
        $limiter = $this->makeLimiter();
        $key = $this->uniqueKey('ban_revert');
        $limit = 1;
        $ttl = 2;
        $maxAttempts = 2;
        $banTimeFrame = 10;
        $banTtl = 3;
        $clientIp = null;

        $this->alignToBucketBoundary($ttl);

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // not limited
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 1

        sleep(2 * $ttl); // normal-window bucket naturally expires

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // not limited (fresh bucket)
        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // violation_count = 2 = maxAttempts

        sleep(2 * $ttl); // this normal-window bucket also naturally expires

        $limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp); // ban window opens ($banTtl=3)

        sleep(2 * $banTtl); // ban bucket naturally expires; violation_count (banTimeFrame=10) also expires by now

        // Reverts to the normal $ttl namespace, long after both the ban
        // bucket and the pre-ban normal buckets have naturally expired.
        $this->assertFalse($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp));
    }
}
