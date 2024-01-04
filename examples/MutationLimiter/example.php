<?php
declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

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
