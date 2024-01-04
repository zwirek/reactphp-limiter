<?php
declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

$loop = \React\EventLoop\Loop::get();

$limiter = new \Zwirek\Limiter\RateLimiter(2, function() use ($loop) {
    $deferred = new \React\Promise\Deferred();

    echo 'execute', PHP_EOL;

    $loop->addTimer(1, function() use ($deferred) {
        $deferred->resolve('return ' . PHP_EOL);
    });

    return $deferred->promise();
});

for ($i = 1; $i <= 10; $i++) {
    $limiter->handle()->then(function($resolve) { echo $resolve; });
}

$loop->run();