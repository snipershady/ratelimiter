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
 * Description of RatelimiterService
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RateLimiterServiceAPCu extends AbstractRateLimiterService {

    /**
     * {@inheritDoc}
     */
    public function isLimited(string $key, int $limit, int $ttl): bool {
        $this->checkKey($key);
        $this->checkTTL($ttl);
        $step = 1;
        $success = null;

        $actual = (int) apcu_fetch($key);
var_dump($actual); 
        if ($actual > $limit) {
            return true;
        }

        if ($actual === 0) {
            var_dump("ora zero e setto expire");
            $actual = apcu_inc($key, $step, $success, $ttl);
            var_dump(apcu_key_info($key)["ttl"]);
        } else {
            var_dump("maggiore di zero");
            $actual = apcu_store($key, $actual+1);
            var_dump(apcu_key_info($key)["ttl"]);
        }

        return $actual > $limit;
    }

}
