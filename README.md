# Rate Limiter

[![PHP Version](https://img.shields.io/badge/php-%5E8.3-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--3.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Packagist](https://img.shields.io/packagist/v/snipershady/ratelimiter.svg)](https://packagist.org/packages/snipershady/ratelimiter)

A free and easy-to-use rate limiter for PHP applications.

## Context

You need to limit network traffic access to a specific function in a specific timeframe.
Rate limiting may help to stop some kinds of malicious activity such as brute force attacks, DDoS, and API abuse.

## Installation

```bash
composer require snipershady/ratelimiter
```

## Requirements

### Composer packages

| Package | Version | Notes |
|---------|---------|-------|
| PHP | ^8.3 | minimum version |
| predis/predis | ^3.2 | required only for `CacheEnum::REDIS` |

### System extensions

Native PHP extensions are not managed by Composer. Install only the ones needed by the backends you use.

| Extension | Required by |
|-----------|-------------|
| ext-apcu | `CacheEnum::APCU` |
| ext-redis | `CacheEnum::PHP_REDIS` |
| ext-memcached | `CacheEnum::MEMCACHED` |

### Debian / Ubuntu

```bash
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# APCu
apt-get install php${PHP_VER}-apcu

# Redis (php-redis native extension)
apt-get install php${PHP_VER}-redis

# Memcached (php-memcached native extension — note the 'd')
apt-get install php${PHP_VER}-memcached
```

### CLI Usage

For CLI usage, remember to enable APCu in your `php.ini`:

```ini
apc.enable_cli=1
```

## Available Cache Backends

| Backend | Enum | Description |
|---------|------|-------------|
| APCu | `CacheEnum::APCU` | Local in-memory cache, no external server required |
| Predis | `CacheEnum::REDIS` | Redis via Predis library (pure PHP) |
| PhpRedis | `CacheEnum::PHP_REDIS` | Redis via php-redis native extension (better performance) |
| Memcached | `CacheEnum::MEMCACHED` | Memcached via php-memcached native extension |

## Algorithms

| Algorithm | Enum | Description |
|-----------|------|--------------|
| Fixed window | `AlgorithmEnum::FIXED_WINDOW` (default) | A single counter per key, reset when its TTL expires. Simple and cheap, but allows up to 2x `$limit` requests to pass across a single window boundary. |
| Sliding window | `AlgorithmEnum::SLIDING_WINDOW` | Smooths that boundary-burst problem. See below for the precision trade-off between backends. |

Select the algorithm with a fourth, optional argument to `factory()` — every existing call site that omits it keeps compiling and behaving exactly as before:

```php
use RateLimiter\Enum\AlgorithmEnum;

$limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis, AlgorithmEnum::SLIDING_WINDOW);
```

`isLimited()`, `isLimitedWithBan()`, `clearRateLimitedKey()` and `clearBan()` keep the exact same signatures regardless of algorithm — only the object `factory()` hands you changes.

### Sliding window precision differs by backend

`AlgorithmEnum::SLIDING_WINDOW` is implemented differently depending on the backend, because each one offers different primitives:

- **Redis (`REDIS` / `PHP_REDIS`)** — an **exact** sliding-window log: every request's timestamp is recorded in a per-key sorted set, and only requests within the trailing `$ttl` seconds are ever counted. No approximation.
- **Memcached / APCu** — a **sliding window counter**: an O(1)-memory approximation using two counters (the current and previous `$ttl`-sized bucket), combined with a linear decay weight. This smooths the fixed window's boundary burst down to a small, bounded overcount, without the unbounded memory growth (or a CAS-loop that can spin forever) that an exact log would require on these backends.

Practical consequence: the **same** `($key, $limit, $ttl)` triple can allow a slightly different number of requests across a window boundary depending on which backend you pick. Neither backend is "wrong" — Redis just affords a data structure (sorted sets) that Memcached/APCu don't, and the counter approximation's error is small and bounded (never worse than the fixed window's own 2x-at-the-boundary behavior).

The ban-violation counter (`isLimitedWithBan()`'s `$maxAttempts`/`$banTimeFrame` tracking) is **always** fixed-window, on every backend and every algorithm — banning is a hard, deliberate action, not something that benefits from smoothing.

> **Known limitation:** for the Memcached/APCu sliding window counter, `isLimitedWithBan()` swapping between the normal `$ttl` and `$banTtl` for the same `$key` switches to a different internal bucket namespace. The old namespace is left to expire on its own rather than eagerly deleted (eager deletion would risk prematurely ending an active ban if the violation counter happens to expire before `$banTtl` does — a normal, intentional configuration, see the `$banTimeFrame`/`$banTtl` example above). In practice this means a `$key` switching between two `$ttl` values in quick succession may see a small, temporary residual count from the abandoned namespace for up to that namespace's own `2 * $ttl` seconds — never longer, and never a permanent leak.

## API Reference

### `isLimited(string $key, int $limit, int $ttl): bool`

Check if a key has exceeded the rate limit.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | Unique identifier for the rate limit (e.g., `__METHOD__`) |
| `$limit` | int | Maximum number of attempts allowed |
| `$ttl` | int | Time window in seconds |

**Returns:** `true` if the limit has been exceeded, `false` otherwise.

### `isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool`

Check if a key has exceeded the rate limit, with progressive ban support for repeat offenders.
Each violation (a request that exceeds `$limit` within `$ttl`) increments a per-client counter.
When that counter reaches `$maxAttempts` within the `$banTimeFrame` observation window, the client
is banned: its next time window is extended to `$banTtl` instead of the normal `$ttl`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | Unique identifier for the rate limit |
| `$limit` | int | Maximum number of requests allowed in `$ttl` seconds |
| `$ttl` | int | Normal time window in seconds |
| `$maxAttempts` | int | Number of violations allowed before a ban is applied |
| `$banTimeFrame` | int | Observation window in seconds during which violations are counted. The violation counter resets after `$banTimeFrame` seconds from the first violation, regardless of subsequent activity (fixed window). |
| `$banTtl` | int | Extended time window in seconds applied when the client is banned (`$banTtl` replaces `$ttl` for the duration of the ban) |
| `$clientIp` | string\|null | When provided, each IP address maintains its own independent violation counter. Pass `null` to apply a shared global counter for the key. |

**Returns:** `true` if the limit has been exceeded, `false` otherwise.

#### How the three time parameters interact

```
$ttl          Normal window: max $limit requests every $ttl seconds
$banTimeFrame Observation window: counts how many times the limit was
              exceeded. Resets $banTimeFrame seconds after the first violation.
$banTtl       Punishment window: replaces $ttl when the client has exceeded
              the limit $maxAttempts times within $banTimeFrame seconds.
```

**Concrete timeline** — `$limit=1, $ttl=5s, $maxAttempts=2, $banTimeFrame=30s, $banTtl=120s`:

```
 t=0s   Request 1: allowed  (counter=1, within limit)
 t=1s   Request 2: BLOCKED  → violation #1 recorded, violation TTL=30s starts
 t=6s   Normal window ($ttl=5s) expired
 t=6s   Request 3: allowed  (new window, violation_count=1 < maxAttempts=2)
 t=7s   Request 4: BLOCKED  → violation #2 recorded  ← ban threshold reached!
        violation_count=2 expires at t≈30s (banTimeFrame from t≈1s)
 t=12s  Normal window expired
 t=12s  Request 5: allowed  (new window; but violation_count=2 ≥ maxAttempts
                              → window is extended: this key now lives 120s)
 t=13s  Request 6: BLOCKED  (inside the 120s ban window)
 ...    All requests blocked until t≈132s (t=12 + banTtl=120)
 t=31s  Violation counter expired (banTimeFrame=30s from t≈1s)
 t=132s Ban window ($banTtl=120s) expired
 t=132s Request N: allowed  (violation_count=0, normal $ttl=5s applies again)
```

> **Note:** a ban is only applied when the request key's window is next (re)created — it is
> not retroactive mid-window. In the timeline above, the ban threshold is reached at `t=7s`
> but the extended `$banTtl` window only starts at `t=12s`, once the normal `$ttl` window
> naturally expires. With a long `$ttl`, a client that just tripped the threshold can keep
> operating under the old, non-banned window for up to the remainder of that period.

### `clearRateLimitedKey(string $key): bool`

Remove a rate limit key, resetting its counter. Note: when the key was managed with
`isLimitedWithBan`, this does **not** lift an active ban — the ban violation counter
lives under a separate internal key. Use `clearBan` to actually unban a client.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | The key to clear |

**Returns:** `true` on success, `false` on failure.

### `clearBan(string $key, ?string $clientIp = null): bool`

Clears both the request counter and the ban violation counter for `$key`, immediately
lifting an active ban. `$clientIp` must match the value passed to `isLimitedWithBan`
(or be omitted/`null` if a shared global counter was used), so the correct per-IP
violation counter is targeted.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | The key to unban |
| `$clientIp` | string\|null | Must match the `$clientIp` used with `isLimitedWithBan`, if any |

**Returns:** `true` if either counter was actually cleared, `false` if there was nothing to clear.

### Input Validation

Every method validates its arguments and throws `\InvalidArgumentException` on the first
violation, before touching the cache backend:

| Parameter | Rule |
|-----------|------|
| `$key` | Non-empty, at most 128 bytes |
| `$clientIp` | When not `null`, at most 45 bytes (covers any IPv6 literal) |
| `$ttl`, `$banTtl`, `$banTimeFrame` | Positive integer (`> 0`) |
| `$maxAttempts` | Positive integer (`> 0`) — a non-positive value would otherwise apply `$banTtl` unconditionally from the very first request |

The `$key`/`$clientIp` length caps exist because the internal ban-tracking key is built as
`BAN_violation_count_<key>_<clientIp>`, which must stay safely under Memcached's hard
250-byte key limit regardless of backend.

## Usage Examples

### Common imports

```php
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
```

---

### APCu

No external server required. Ideal for single-server deployments or CLI tools.

```php
$limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
$key     = __METHOD__;
$limit   = 2;
$ttl     = 3;

if ($limiter->isLimited($key, $limit, $ttl)) {
    throw new \Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
}
```

---

### Sliding window

Same four methods, same parameters — only the `factory()` call changes. See
[Algorithms](#algorithms) for the precision trade-off between backends.

```php
use RateLimiter\Enum\AlgorithmEnum;

$limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis, AlgorithmEnum::SLIDING_WINDOW);
$key     = __METHOD__;
$limit   = 2;
$ttl     = 3;

if ($limiter->isLimited($key, $limit, $ttl)) {
    throw new \Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
}
```

---

### Redis — Predis

Pure-PHP Redis client; no native extension required.

```php
$redis = new Client([
    'scheme'     => 'tcp',
    'host'       => '192.168.0.100',
    'port'       => 6379,
    'persistent' => true,
]);

$limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);
$key     = __METHOD__;
$limit   = 2;
$ttl     = 3;

if ($limiter->isLimited($key, $limit, $ttl)) {
    throw new \Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
}
```

---

### Redis — PhpRedis

Native `ext-redis` extension; better raw performance than Predis.

```php
$redis = new \Redis();
$redis->pconnect(
    '192.168.0.100',
    6379,
    2,
    'persistent_id_rl'
);

$limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis);
$key     = __METHOD__;
$limit   = 2;
$ttl     = 3;

if ($limiter->isLimited($key, $limit, $ttl)) {
    throw new \Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
}
```

---

### Memcached

Requires `ext-memcached`. Passing a `persistent_id` reuses the connection pool across
requests; the `getServerList()` guard prevents registering the same server twice.

```php
$memcached = new \Memcached('persistent_id_rl');
if (!$memcached->getServerList()) {
    $memcached->addServer('192.168.0.100', 11211);
}

$limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $memcached);
$key     = __METHOD__;
$limit   = 2;
$ttl     = 3;

if ($limiter->isLimited($key, $limit, $ttl)) {
    throw new \Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
}
```

**Two Memcached-specific behaviours worth knowing:**

- **Long TTLs just work.** Memcached's protocol treats an `exptime` greater than 30 days
  (2,592,000 seconds) as an absolute Unix timestamp rather than a relative offset. This
  backend automatically converts any `$ttl`/`$banTtl` above that threshold into an absolute
  timestamp internally, so you can pass any plain "seconds from now" value — including a
  multi-month `$banTtl` — without running into that quirk yourself.
- **Backend errors fail closed.** If a genuine Memcached error occurs (server unreachable,
  timeout, etc. — as opposed to a normal cache miss), the library throws `\RuntimeException`
  instead of silently treating the request as unlimited. A rate limiter is a security
  control, so an infrastructure failure should block, not bypass, the check; catch
  `\RuntimeException` if you need custom fallback behaviour during an outage.

---

### Rate Limit with Ban

Use `isLimitedWithBan` when you want to progressively punish repeat offenders with longer
block windows. The only difference between backends is the factory call — the parameters
and behaviour are identical.

#### With APCu

```php
$limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);

$key          = __METHOD__;
$limit        = 5;
$ttl          = 60;
$maxAttempts  = 3;
$banTimeFrame = 300;
$banTtl       = 3600;
$clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
    throw new \RuntimeException("Too many login attempts. Please try again later.");
}
```

#### With Predis

```php
$redis = new Client([
    'scheme'     => 'tcp',
    'host'       => '192.168.0.100',
    'port'       => 6379,
    'persistent' => true,
]);

$limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);

$key          = __METHOD__;
$limit        = 5;
$ttl          = 60;
$maxAttempts  = 3;
$banTimeFrame = 300;
$banTtl       = 3600;
$clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
    throw new \RuntimeException("Too many login attempts. Please try again later.");
}
```

#### With PhpRedis

```php
$redis = new \Redis();
$redis->pconnect('192.168.0.100', 6379, 2, 'persistent_id_rl');

$limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis);

$key          = __METHOD__;
$limit        = 5;
$ttl          = 60;
$maxAttempts  = 3;
$banTimeFrame = 300;
$banTtl       = 3600;
$clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
    throw new \RuntimeException("Too many login attempts. Please try again later.");
}
```

#### With Memcached

```php
$memcached = new \Memcached('persistent_id_rl');
if (!$memcached->getServerList()) {
    $memcached->addServer('192.168.0.100', 11211);
}

$limiter = AbstractRateLimiterService::factory(CacheEnum::MEMCACHED, $memcached);

$key          = __METHOD__;
$limit        = 5;
$ttl          = 60;
$maxAttempts  = 3;
$banTimeFrame = 300;
$banTtl       = 3600;
$clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
    throw new \RuntimeException("Too many login attempts. Please try again later.");
}
```

#### Understanding `$banTimeFrame`

`$banTimeFrame` is the **observation window** that determines how long a violation is
"remembered". It answers the question: *"How many times has this client exceeded the limit
in the last N seconds?"*.

```
$ttl          → How long each rate-limit window lasts (normal behaviour)
$banTimeFrame → How long violations are tracked (observation window)
$banTtl       → How long a ban lasts once the client is flagged
```

The violation counter is a fixed window starting at the **first** violation:
- It does **not** reset on each new violation (no sliding window).
- After `$banTimeFrame` seconds it expires and the client is "forgiven".

**Visual example** — `$limit=5, $ttl=60s, $maxAttempts=3, $banTimeFrame=300s, $banTtl=3600s`:

```
 t=0s     6 rapid requests → 5 allowed, 1 BLOCKED  → violation #1 (counter TTL = 300s)
 t=60s    Window resets. 6 requests again           → violation #2
 t=120s   Window resets. 6 requests again           → violation #3  ← ban threshold!
           violation_count = 3 >= maxAttempts=3
 t=180s   Window resets. Client tries again:
           violation_count still alive (expires at t≈300s)
           → ban applied: new window is 3600s instead of 60s
           → client blocked for 1 hour
 t=300s   Violation counter expires (banTimeFrame elapsed from t=0)
 t=3780s  Ban window expires (t=180 + banTtl=3600)
 t=3780s  Client can try again with a fresh violation counter
```

#### Per-client isolation with `$clientIp`

When `$clientIp` is provided, each IP address has its own independent violation counter.
Banning `192.168.1.1` has no effect on `192.168.1.2`:

```php
// Client A: banned after 3 violations
$limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, '192.168.1.1');

// Client B: unaffected, starts from zero violations
$limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, '192.168.1.2');
```

Pass `null` to use a **shared global counter** for the key (all clients contribute to
the same violation count — useful when you want to protect a resource globally regardless
of origin).

---

### Clearing a Rate Limit Key

`clearRateLimitedKey` resets the counter for a given key immediately. Useful after a
successful authentication or during testing.

```php
// Works identically for every backend — swap the factory call as needed.
$limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);

$key = 'App\Controller\LoginController::login';

if ($limiter->clearRateLimitedKey($key)) {
    // Counter reset; the next request will be treated as the first in a new window.
}
```

When using `isLimitedWithBan`, this method alone does **not** lift an active ban: the
ban violation counter lives under a separate internal key and drives the ban independently
of the request counter. Use `clearBan` instead to actually unban a client.

### Manually Lifting a Ban

`clearBan` clears both the request counter and the violation counter for a key, so the
very next request is treated as the first in a fresh window instead of immediately
re-triggering the ban.

```php
$limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);

$key = 'App\Controller\LoginController::login';
$clientIp = $_SERVER['REMOTE_ADDR'];

if ($limiter->clearBan($key, $clientIp)) {
    // Client is unbanned immediately.
}
```

Pass the same `$clientIp` (or omit it) that was used with `isLimitedWithBan`, so the
correct violation counter — per-IP or shared — is cleared.

---

## Development

### Dev dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| phpunit/phpunit | ^13.2 | test runner |
| phpstan/phpstan | ^2.2 | static analysis |
| friendsofphp/php-cs-fixer | ^3.90 | code style |
| rector/rector | ^2.4.5 | automated refactoring |

### Available Scripts

| Command | Description |
|---------|-------------|
| `composer test` | Run the full PHPUnit suite (unit + integration) |
| `composer test:unit` | Run only the fast unit suite — mocked, no external services, milliseconds |
| `composer test:integration` | Run only the integration suite — needs live APCu/Redis/Memcached, several minutes |
| `composer phpstan` | Run PHPStan static analysis |
| `composer cs-fix` | Fix code style with PHP-CS-Fixer |
| `composer cs-check` | Check code style (dry-run) |
| `composer rector` | Run Rector refactoring |
| `composer rector-dry` | Preview Rector changes |
| `composer quality` | Run all quality tools (Rector + CS-Fixer) |
| `composer quality-check` | Check quality without changes |

### Test suite layout

Tests are split into two PHPUnit testsuites (`phpunit.xml`):

- **`tests/Unit/`** — no external services, mocked cache clients, runs in milliseconds.
- **`tests/Integration/`** — exercises real APCu/Redis/Memcached backends with real TTL expiry, so it takes several minutes (`sleep()`-driven).

Within `tests/Integration/`, backend behaviour that is identical across every cache is defined once in `Contract/AbstractRateLimiterContractTestCase` and inherited by each concrete backend class; the two Redis backends (Predis and php-redis) additionally share `Contract/AbstractRedisFamilyContractTestCase`, since both expose the applied TTL identically via `ttl()`. APCu and Memcached implement their own ban-lifecycle tests instead of sharing that second layer, because neither exposes the remaining TTL the same way Redis does. A concrete backend class stays thin — it only wires up a connection and adds tests for genuinely backend-specific behaviour (e.g. php-redis's WRONGTYPE fail-closed handling, or Memcached's 30-day TTL threshold quirk).

`AlgorithmEnum::SLIDING_WINDOW` has its own pair of contract base classes, mirroring the fixed-window ones above but with timing assertions specific to that algorithm's behaviour: `Contract/AbstractRedisSlidingLogContractTestCase` (Predis/php-redis, exact log — `PredisSlidingWindowRateLimiterTest`/`PhpRedisSlidingWindowRateLimiterTest`) and `Contract/AbstractSlidingWindowCounterContractTestCase` (Memcached/APCu, two-bucket approximation — `MemcachedSlidingWindowRateLimiterTest`/`ApcuSlidingWindowRateLimiterTest`). The latter aligns every timing-sensitive test to an exact bucket boundary first (`alignToBucketBoundary()`), so outcomes are a deterministic function of the chosen `sleep()`s instead of depending on wall-clock phase at the moment the test happens to run.

## License

This project is licensed under the GPL-3.0-or-later License - see the [LICENSE](LICENSE) file for details.

## Author

**Stefano Perrini** - [spinfo.it](https://www.spinfo.it)
