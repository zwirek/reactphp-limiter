<?php
declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

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
