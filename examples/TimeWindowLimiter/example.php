<?php
declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

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
