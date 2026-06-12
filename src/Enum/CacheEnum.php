<?php

namespace RateLimiter\Enum;

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
 * Identifies the cache backend to use when building a rate limiter via the factory.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum CacheEnum: int
{
    /**
     * APCu in-memory cache.
     * Requires the PHP APCu extension: ext-apcu (e.g. "ext-apcu": "*" in composer.json).
     * No additional PHP class or external library needed beyond the extension itself.
     */
    case APCU = 1;

    /**
     * Redis cache via the Predis client library.
     * Requires the Composer package predis/predis (e.g. "predis/predis": "*" in composer.json).
     * No native PHP extension needed; the client is pure PHP.
     */
    case REDIS = 2;

    /**
     * Redis cache via the native PHP Redis extension.
     * Requires the PHP Redis extension: ext-redis (e.g. "ext-redis": "*" in composer.json).
     * Exposes the native \Redis class; no Composer package needed.
     */
    case PHP_REDIS = 3;

    /**
     * Memcached cache via the native PHP Memcached extension.
     * Requires the PHP Memcached extension: ext-memcached (e.g. "ext-memcached": "*" in composer.json).
     * Exposes the native \Memcached class; no Composer package needed.
     */
    case MEMCACHED = 4;
}
