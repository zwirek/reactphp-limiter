
zwirek/reactphp-limiter
=======================

Set of limiters designed to use with [ReactPHP](https://github.com/reactphp)

## Table of content

1. [Introduction](#introduction)
2. [Usage](#usage)
    * [RateLimiter](#ratelimiter)
    * [TimeWindowLimiter](#timewindowlimiter)
    * [MutationLimiter](#mutationlimiter)
3. [Limiter Interface](#limiter-interface)

## Introduction

This repository contains several limiter classes designed for use with ReactPHP.
Each limiter must have a registered handler (callable) that will be called immediately for each
request before the limit is reached. Any additional request over the limit 
will be queued. There are different limiters for different purposes:
- RateLimiter: Limits simultaneous handler execution for a given limit
- TimeWindowLimiter: For time window, limit handler execution to the specified limit.
- MutationLimiter: Limits handler execution against a resource.

## Usage

### RateLimiter

Limit concurrent handler execution to given limit

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\RateLimiter(2, function($counter) use ($loop) {
    $deferred = new \React\Promise\Deferred();

    echo 'execute for ', $counter, PHP_EOL;

    $loop->addTimer(2, function() use ($deferred, $counter) {
        $deferred->resolve('return ' . $counter . PHP_EOL);
    });

    return $deferred->promise();
});

for ($i = 1; $i <= 10; $i++) {
    $limiter->handle($i)->then(function($resolve) { echo $resolve; });
}

$loop->run();
```
Handler will only be fired twice at the same time.

It is possible to limit the number of jobs waiting to be executed by setting an overflow limit  

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\RateLimiter(2, function($counter) use ($loop) {
    $deferred = new \React\Promise\Deferred();

    echo 'execute for ', $counter, PHP_EOL;

    $loop->addTimer(2, function() use ($deferred, $counter) {
        $deferred->resolve('return ' . $counter . PHP_EOL);
    });

    return $deferred->promise();
}, 5);

for ($i = 1; $i <= 10; $i++) {
    $limiter->handle($i)
        ->then(
            function ($resolve) {
                echo $resolve;
            },
            function (OverflowException $exception) use ($i) {
                echo 'Overflow limit reached for call ', $i, PHP_EOL;
            }
        );
}

$loop->run();
```
Calls above the limit are immediately rejected.

### TimeWindowLimiter

This limiter is responsible for limiting handler execution under a given limit within a time window. For example
it is possible to limit a job execution to 100 times every 1 minute.

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\TimeWindowLimiter(2, 500, function($counter) use ($loop) {
    $deferred = new \React\Promise\Deferred();

    echo 'execute for ', $counter, PHP_EOL;

    $loop->addTimer(1, function() use ($deferred, $counter) {
        $deferred->resolve('return ' . $counter . PHP_EOL);
    });

    return $deferred->promise();
});

for ($i = 1; $i <= 10; $i++) {
    $limiter->handle($i)->then(function($resolve) { echo $resolve; });
}

$loop->run();
```
For this example, the handler is called twice every half second. The next calls will start immediately when the next
window starts, even if jobs from the previous window are in pending state.

It is possible to limit the number of jobs waiting to be executed by setting an overflow limit.

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\TimeWindowLimiter(2, 500, function($counter) use ($loop) {
    $deferred = new \React\Promise\Deferred();

    echo 'execute for ', $counter, PHP_EOL;

    $loop->addTimer(1, function() use ($deferred, $counter) {
        $deferred->resolve('return ' . $counter . PHP_EOL);
    });

    return $deferred->promise();
}, 5);

for ($i = 1; $i <= 10; $i++) {
    $limiter->handle($i)
        ->then(
            function ($resolve) {
                echo $resolve;
            },
            function (OverflowException $exception) use ($i) {
                echo 'Overflow limit reached for call ', $i, PHP_EOL;
            }
        );
}

$loop->run();
```

### MutationLimiter

This limiter can limit concurrent job calls for specific resource. Resource can be anything like file, connection, row in database
because limiter needs additional callback that returns resource id. Resource id must be string or int or float.

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\MutationLimiter(
    function($counter, $resource) use ($loop) {
        $deferred = new \React\Promise\Deferred();

        echo 'execute counter ', $counter, ' for resource ', $resource, PHP_EOL;

        $loop->addTimer(1, function() use ($deferred, $counter) {
            $deferred->resolve('return ' . $counter . PHP_EOL);
        });

        return $deferred->promise();
    },
    function($counter, $resource) {
        return $resource;
    }
);

$successCallback = function ($resolve) {
    echo $resolve;
};

for ($i = 1; $i <= 5; $i++) {
    $limiter->handle($i, 1)->then($successCallback);
    $limiter->handle($i, 2)->then($successCallback);
    $limiter->handle($i, 3)->then($successCallback);
}

$loop->run();
```
Second callback is responsible for providing resource id. It gets the same data as argument as handler callback.
In this way it is possible to resolve resource id based on given data.

It is possible to limit the number of jobs waiting to be executed per resource by setting an overflow limit.

```php
$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\MutationLimiter(
    function($counter, $resource) use ($loop) {
        $deferred = new \React\Promise\Deferred();

        echo 'execute counter ', $counter, ' for resource ', $resource, PHP_EOL;

        $loop->addTimer(1, function() use ($deferred, $counter) {
            $deferred->resolve('return ' . $counter . PHP_EOL);
        });

        return $deferred->promise();
    },
    function($counter, $resource) {
        return $resource;
    },
    4
);

$successCallback = function ($resolve) {
    echo $resolve;
};
$failureCallback = function (OverflowException $exception) {
    echo $exception->getMessage(), PHP_EOL;
};

for ($i = 1; $i <= 5; $i++) {
    $limiter->handle($i, 1)->then($successCallback, $failureCallback);
    $limiter->handle($i, 2)->then($successCallback, $failureCallback);
    $limiter->handle($i, 3)->then($successCallback, $failureCallback);
}

$loop->run();
```

## Limiter Interface

Every limiter class implement `\Zwirek\Limiter\Limiter` interface. Limiter interface have only one public method. 
```php
\Zwirek\Limiter\Limiter::handle(mixed ...$arguments): \React\Promise\Promise
```

The handler can be called with zero or more arguments. It is important to call `::handle` with at least the number of arguments
as a registered handler callback.
