<?php

declare(strict_types=1);

namespace RateLimiter\Adapter;

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
 * RedisAdapterInterface implementation for the php-redis native extension.
 *
 * php-redis uses multi()->exec() for MULTI/EXEC blocks.
 * The exec() result is a plain array indexed by command order:
 *   [0] => result of the first command
 *   [1] => result of the second command
 *   ...
 *
 * Unlike Predis, php-redis does not throw on a server error reply (e.g.
 * WRONGTYPE when $key already holds a non-numeric value): the failing call
 * simply returns false, with details available via getLastError(). Every
 * method below must therefore check for that false explicitly and fail
 * closed (throw) instead of letting `(int) false === 0` be silently
 * mistaken for a legitimate zero count — a rate limiter that goes quiet on
 * a backend error stops limiting instead of blocking traffic.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class PhpRedisAdapter implements RedisAdapterInterface
{
    public function __construct(private readonly \Redis $client)
    {
    }

    #[\Override]
    public function increment(string $key): int
    {
        $this->client->clearLastError();
        $result = $this->client->incr($key);

        // INCR has no legitimate false outcome (a missing key is created at
        // 1), so any false here is a genuine backend error.
        if (false === $result) {
            throw $this->failure('INCR', $key);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * exec()[0] = EXPIRE result (1 = success)
     * exec()[1] = GET result   (the current counter value as a string).
     *
     * Only called right after increment() just created/bumped $key, so the
     * key is guaranteed to exist here: a false GET result inside the
     * transaction cannot be a legitimate miss and always indicates an error.
     */
    #[\Override]
    public function expireAndGet(string $key, int $ttl): int
    {
        $this->client->clearLastError();
        $result = $this->client->multi()->expire($key, $ttl)->get($key)->exec();

        if (false === $result || false === ($result[1] ?? false)) {
            throw $this->failure('EXPIRE/GET transaction', $key);
        }

        return (int) $result[1];
    }

    /**
     * {@inheritDoc}
     * A false result from GET is ambiguous by itself: php-redis returns it
     * both for a legitimate cache miss (key doesn't exist yet, no error) and
     * for a genuine backend error (e.g. WRONGTYPE). getLastError() is what
     * disambiguates the two: it is set only in the error case.
     */
    #[\Override]
    public function get(string $key): int
    {
        $this->client->clearLastError();
        $result = $this->client->get($key);

        if (false === $result && null !== $this->client->getLastError()) {
            throw $this->failure('GET', $key);
        }

        return (int) $result;
    }

    #[\Override]
    public function expire(string $key, int $ttl): void
    {
        $this->client->expire($key, $ttl, 'NX');
    }

    #[\Override]
    public function del(string $key): int
    {
        return (int) $this->client->del($key);
    }

    private function failure(string $operation, string $key): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Redis %s failed for key "%s": %s',
            $operation,
            $key,
            $this->client->getLastError() ?? 'unknown error',
        ));
    }
}
