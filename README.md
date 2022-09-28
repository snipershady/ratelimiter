# ratelimiter
A free and easy-to-use rate limiter

## Context
You need to limit network traffic access to a specific function in a specific timeframe.
Rate limiting may help to stop some kinds of malicious activity.


```bash
composer require snipershady/ratelimiter
```

## Command Line Interface (CLI)
For CLI usage, remember to edit your php.ini file to enable the APC extension

```bash
apc.enable_cli="1"
```

### APCu example:

```php
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;

class Foo(){
    public function controllerYouWantToRateLimit(): Response {
            $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
            $key = __METHOD__;  //Name of the function you want to rate limit. You can set a custom key. It's a String!
            $limit = 2;         //Maximum attempts before the limit
            $ttl = 3;           //The timeframe you want to limit access for

            if($limiter->isLimited($key, $limit, $ttl)){
                throw new Exception("You've reached the limit");
            }

            // ... other code
    }
}
```

### Redis Example
```php
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;

class Foo(){
    public function controllerYouWantToRateLimit(): Response {
            $serverIp = "192.168.0.100";        //The server where you've installed the Redis instance.
            $redis = new Client("tcp://$serverIp:6379?persistent=redis01"); // Example with persistent connection.

            $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);
            $key = __METHOD__;  //Name of the function you want to rate limit. You can set a custom key. It's a String!
            $limit = 2;         //Maximum attempts before the limit
            $ttl = 3;           //The timeframe you want to limit access for

            if($limiter->isLimited($key, $limit, $ttl)){
                throw new Exception("You've reached the limit");
            }

            // ... other code
    }
}
```