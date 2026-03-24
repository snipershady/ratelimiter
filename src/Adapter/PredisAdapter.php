<?php

namespace RateLimiter\Adapter;

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
 * RedisAdapterInterface implementation for the Predis client library.
 *
 * Predis uses transaction()->execute() for MULTI/EXEC blocks.
 * The execute() result is a plain array indexed by command order:
 *   [0] => result of the first command
 *   [1] => result of the second command
 *   ...
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class PredisAdapter implements RedisAdapterInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    #[\Override]
    public function increment(string $key): int
    {
        return (int) $this->client->incr($key);
    }

    /**
     * {@inheritDoc}
     * execute()[0] = EXPIRE result (1 = success)
     * execute()[1] = GET result   (the current counter value as a string).
     */
    #[\Override]
    public function expireAndGet(string $key, int $ttl): int
    {
        $result = $this->client->transaction()->expire($key, $ttl)->get($key)->execute();

        return (int) ($result[1] ?? 0);
    }

    #[\Override]
    public function get(string $key): int
    {
        return (int) $this->client->get($key);
    }

    #[\Override]
    public function expire(string $key, int $ttl): void
    {
        $this->client->expire($key, $ttl);
    }

    #[\Override]
    public function del(string $key): int
    {
        return (int) $this->client->del($key);
    }
}
