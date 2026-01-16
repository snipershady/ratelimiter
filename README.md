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

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | Unique identifier for the rate limit |
| `$limit` | int | Maximum number of attempts allowed |
| `$ttl` | int | Base time window in seconds |
| `$maxAttempts` | int | Max violations before triggering a ban |
| `$banTimeFrame` | int | Time window for counting violations |
| `$banTtl` | int | Extended time window when banned |
| `$clientIp` | string\|null | Client IP for per-client banning |

**Returns:** `true` if the limit has been exceeded, `false` otherwise.

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

Use this when you want to progressively punish repeat offenders with longer timeouts.

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
        $limit = 1;             // Max attempts before limit
        $ttl = 2;               // Base time window (seconds)
        $maxAttempts = 3;       // Violations before ban kicks in
        $banTimeFrame = 4;      // Time window for counting violations
        $banTtl = 10;           // Extended timeout when banned
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp)) {
            throw new Exception("LIMIT REACHED: YOU SHALL NOT PASS!");
        }

        // ... your code
    }
}
```

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
