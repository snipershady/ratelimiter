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

## Prerequisites
To install the package you need at least the php-apcu and php-redis extension installed.
To use the most secure strategy, with Redis, you need a Redis server installed and accessible.

Debian - Ubuntu
```bash
apt-get install php8.4-redis php8.4-apcu
# you can install php-redis and php-apcu module for the version you've installed on the system
# min version required 8.2
```

## Legacy PHP 5.6 version
If you are a sad developer forced to still use a deprecated version of PHP, ask me in private, and I will release a legacy version of the package for you.

### APCu example:

## Load dependencies 
```php
use Predis\Client;
use RateLimiter\Enum\CacheEnum;
use RateLimiter\Service\AbstractRateLimiterService;
```

### APCu Example
```php
class Foo(){
    public function controllerYouWantToRateLimit(): Response {
        $limiter = AbstractRateLimiterService::factory(CacheEnum::APCU);
        $key = __METHOD__;  //Name of the function you want to rate limit. You can set a custom key. It's a String!
        $limit = 2;         //Maximum attempts before the limit
        $ttl = 3;           //The timeframe you want to limit access for

        if($limiter->isLimited($key, $limit, $ttl)){
            throw new Exception("LIMIT REACHED: YOOUUU SHALL NOOOOT PAAAAAAASSS");
        }

        // ... other code
    }
}
```

### Redis Example with predis client
```php

class Foo(){
    public function controllerYouWantToRateLimit(): Response {
        $serverIp = "192.168.0.100";        //The server where you've installed the Redis instance.
        // Example with persistent connection.
        $redis = new Client([
            'scheme' => 'tcp',
            'host' => $serverIp,
            'port' => 6379,
            'persistent' => true,
        ]); 
         

        $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $redis);
        $key = __METHOD__;  //Name of the function you want to rate limit. You can set a custom key. It's a String!
        $limit = 2;         //Maximum attempts before the limit
        $ttl = 3;           //The timeframe you want to limit access for

        if($limiter->isLimited($key, $limit, $ttl)){
            throw new Exception("LIMIT REACHED: YOOUUU SHALL NOOOOT PAAAAAAASSS");
        }
        // ... other code
    }
}
```

### Redis Example with php-redis
```php

class Foo(){
    public function controllerYouWantToRateLimit(): Response {
        $serverIp = "192.168.0.100";        //The server where you've installed the Redis instance.
        // Example with persistent connection.
        $redis = new \Redis();
        redis->pconnect(
            $serverIp, // host
            6379, // port
            2, // connectTimeout
            'persistent_id_rl_test'         // persistent_id
        );
         

        $limiter = AbstractRateLimiterService::factory(CacheEnum::PHP_REDIS, $redis);
        $key = __METHOD__;  //Name of the function you want to rate limit. You can set a custom key. It's a String!
        $limit = 2;         //Maximum attempts before the limit
        $ttl = 3;           //The timeframe you want to limit access for

        if($limiter->isLimited($key, $limit, $ttl)){
            throw new Exception("LIMIT REACHED: YOOUUU SHALL NOOOOT PAAAAAAASSS");
        }
        // ... other code
    }
}
```

### Rate Limit with Ban option (example with Redis, but you can use APCu anyway
```php

class Foo(){
    public function controllerYouWantToRateLimit(): Response {
    $serverIp = "192.168.0.100";    //The server where you've installed the Redis instance.
    // Example with persistent connection.
        $redis = new Client([
            'scheme' => 'tcp',
            'host' => $this->servername,
            'port' => $this->port,
            'persistent' => true,
        ]);
    $limiter = AbstractRateLimiterService::factory(CacheEnum::REDIS, $this->redis);
    $key = __METHOD__;  // Name of the function you want to rate limit. You can set a custom key. It's a String!
    $limit = 1;         // Maximum attempts before the limit
    $maxAttempts = 3;   // Max number of attempts you want to allow in a timeframe
    $banTimeFrame = 4;  // Timeframe where maxAttempts should not be reached to avoid the ban
    $ttl = 2;           // The base timeframe you want to limit access for
    $banTtl = 4;        // If a limit is reached greater equals time of max attempts, the new timeframe limit will be 4 seconds
    $clientIp = filter_input(INPUT_SERVER, 'REMOTE_ADDR');  // It is recommended to send the client IP to limit access to a function to a specific address, not to everyone 
    
    if($limiter->isLimitedWithBan($key, $limit, $ttl, $maxAttempts, $banTimeFrame, $banTtl, $clientIp))){
        throw new Exception("LIMIT REACHED: YOOUUU SHALL NOOOOT PAAAAAAASSS");
    }
    // ... other code
    }
}
```