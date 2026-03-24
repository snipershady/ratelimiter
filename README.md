# Rate Limiter

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net/)
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

| Requirement | Version |
|-------------|---------|
| PHP | >= 8.2 |
| ext-apcu | * |
| ext-redis | * |
| predis/predis | ^3.2 |

### Debian / Ubuntu

```bash
apt-get install php8.4-redis php8.4-apcu
# You can install php-redis and php-apcu module for the version you've installed on the system
# Minimum PHP version required: 8.2
```

### CLI Usage

For CLI usage, remember to enable APCu in your `php.ini`:

```ini
apc.enable_cli=1
```

## Available Cache Backends

| Backend | Enum | Description |
|---------|------|-------------|
| APCu | `CacheEnum::APCU` | Local in-memory cache, no external dependencies |
| Predis | `CacheEnum::REDIS` | Redis via Predis library (pure PHP) |
| PhpRedis | `CacheEnum::PHP_REDIS` | Redis via php-redis extension (C extension, better performance) |

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

### `clearRateLimitedKey(string $key): bool`

Remove a rate limit key, resetting its counter.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | The key to clear |

**Returns:** `true` on success, `false` on failure.

## Usage Examples

### Dependencies

```php
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
```

### APCu Example

```php
class Foo
{
    public function controllerYouWantToRateLimit(): Response
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = __METHOD__;  // Name of the function you want to rate limit
        $limit = 2;         // Maximum attempts before the limit
        $ttl = 3;           // Time window in seconds

        if ($limiter->isLimited($key, $limit, $ttl)) {
            throw new Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
        }

        // ... your code
    }
}
```

### Redis Example (Predis)

```php
class Foo
{
    public function controllerYouWantToRateLimit(): Response
    {
        $redis = new Client([
            'scheme' => 'tcp',
            'host' => '192.168.0.100',
            'port' => 6379,
            'persistent' => true,
        ]);

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);
        $key = __METHOD__;
        $limit = 2;
        $ttl = 3;

        if ($limiter->isLimited($key, $limit, $ttl)) {
            throw new Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
        }

        // ... your code
    }
}
```

### Redis Example (PhpRedis)

```php
class Foo
{
    public function controllerYouWantToRateLimit(): Response
    {
        $redis = new \Redis();
        $redis->pconnect(
            '192.168.0.100',        // host
            6379,                   // port
            2,                      // connect timeout
            'persistent_id_rl'      // persistent_id
        );

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis);
        $key = __METHOD__;
        $limit = 2;
        $ttl = 3;

        if ($limiter->isLimited($key, $limit, $ttl)) {
            throw new Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
        }

        // ... your code
    }
}
```

### Rate Limit with Ban

Use this when you want to progressively punish repeat offenders with longer block windows.
Normal rate limiting resets every `$ttl` seconds. With ban support, a client that repeatedly
triggers the limit within the `$banTimeFrame` observation window gets its block window
extended to `$banTtl` seconds instead.

#### With Predis

```php
class LoginController
{
    public function login(): Response
    {
        $redis = new Client([
            'scheme' => 'tcp',
            'host'   => '192.168.0.100',
            'port'   => 6379,
            'persistent' => true,
        ]);

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);

        $key          = __METHOD__;
        $limit        = 5;      // Allow 5 login attempts per window
        $ttl          = 60;     // Normal window: 60 seconds
        $maxAttempts  = 3;      // Ban after 3 violations within $banTimeFrame
        $banTimeFrame = 300;    // Observation window: count violations over 5 minutes
        $banTtl       = 3600;   // Punishment: block for 1 hour when banned
        $clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
            throw new TooManyRequestsException("Too many login attempts. Please try again later.");
        }

        // ... authentication logic
    }
}
```

#### With APCu

```php
class LoginController
{
    public function login(): Response
    {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);

        $key          = __METHOD__;
        $limit        = 5;
        $ttl          = 60;
        $maxAttempts  = 3;
        $banTimeFrame = 300;
        $banTtl       = 3600;
        $clientIp     = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
            throw new TooManyRequestsException("Too many login attempts. Please try again later.");
        }

        // ... authentication logic
    }
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

**`$clientIp` and per-client isolation**

When `$clientIp` is provided, each IP address has its own independent violation counter.
This means banning `192.168.1.1` has no effect on `192.168.1.2`:

```php
// Client A: banned after 3 violations
$limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, '192.168.1.1');

// Client B: unaffected, starts from zero violations
$limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, '192.168.1.2');
```

Pass `null` to use a **shared global counter** for the key (all clients contribute to
the same violation count — useful when you want to protect a resource globally regardless
of origin).

## Development

### Available Scripts

| Command | Description |
|---------|-------------|
| `composer test` | Run PHPUnit tests |
| `composer phpstan` | Run PHPStan static analysis |
| `composer cs-fix` | Fix code style with PHP-CS-Fixer |
| `composer cs-check` | Check code style (dry-run) |
| `composer rector` | Run Rector refactoring |
| `composer rector-dry` | Preview Rector changes |
| `composer quality` | Run all quality tools (Rector + CS-Fixer) |
| `composer quality-check` | Check quality without changes |

## License

This project is licensed under the GPL-3.0-or-later License - see the [LICENSE](LICENSE) file for details.

## Author

**Stefano Perrini** - [spinfo.it](https://www.spinfo.it)
