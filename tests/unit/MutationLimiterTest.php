<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use React\Promise\Deferred;
use utils\SpyInterface;
use utils\TestLoopTrait;
use Zwirek\Limiter\Exception\UnexpectedValueException;
use Zwirek\Limiter\MutationLimiter;
use function React\Promise\reject;

/**
 * @covers \Zwirek\Limiter\MutationLimiter
 * @covers \Zwirek\Limiter\RateLimiter
 */
class MutationLimiterTest extends TestCase
{
    use ProphecyTrait;
    use TestLoopTrait;

    /**
     * @test
     */
    public function shouldExecuteSyncHandlerForTheSameId()
    {
        $mutationLimiter = new MutationLimiter(
            function() {
                return 'sync_return';
            },
            function() {
                return 1;
            },
            10,
        );

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $mutationLimiter->handle('data')->then([$spy, 'resolve']);
        $mutationLimiter->handle('data')->then([$spy, 'resolve']);
        $mutationLimiter->handle('data')->then([$spy, 'resolve']);

        $spyObject->resolve('sync_return')->shouldBeCalledTimes(3);
    }

    /**
     * @test
     */
    public function shouldExecuteSyncHandlerForDifferentIds()
    {
        $mutationLimiter = new MutationLimiter(
            function ($returnValue, $index) {
                return $returnValue;
            },
            function ($returnValue, $index) {
                return $index;
            },
            10,
        );

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $mutationLimiter->handle('data1', 1)->then([$spy, 'resolve']);
        $mutationLimiter->handle('data1', 1)->then([$spy, 'resolve']);
        $mutationLimiter->handle('data2', 2)->then([$spy, 'resolve']);

        $spyObject->resolve('data1')->shouldHaveBeenCalledTimes(2);
        $spyObject->resolve('data2')->shouldHaveBeenCalledTimes(1);
    }

    /**
     * @test
     */
    public function shouldExecuteAsyncHandlerForTheSameId()
    {
        $loop = $this->getLoop();

        $mutationLimiter = new MutationLimiter(
            function() use ($loop) {
                $deferred = new Deferred();

                $loop->addTimer(0.01, function() use ($deferred) {
                    $deferred->resolve('async_resolved');
                });

                return $deferred->promise();
            },
            function() {
                return 1;
            },
            10
        );

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $this->waitForPromises(
            $mutationLimiter->handle('data')->then([$spy, 'resolve']),
            $mutationLimiter->handle('data')->then([$spy, 'resolve']),
            $mutationLimiter->handle('data')->then([$spy, 'resolve']),
        );

        $spyObject->resolve('async_resolved')->shouldHaveBeenCalledTimes(3);
    }

    /**
     * @test
     */
    public function shouldExecuteAsyncHandlerForDifferentIds()
    {
        $loop = $this->getLoop();

        $mutationLimiter = new MutationLimiter(
            function($returnValue, $index) use ($loop) {
                $deferred = new Deferred();

                $loop->addTimer(0.01, function() use ($deferred, $returnValue) {
                    $deferred->resolve($returnValue);
                });

                return $deferred->promise();
            },
            function($returnValue, $index) {
                return $index;
            },
            10
        );

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $this->waitForPromises(
            $mutationLimiter->handle('data1', 1)->then([$spy, 'resolve']),
            $mutationLimiter->handle('data1', 1)->then([$spy, 'resolve']),
            $mutationLimiter->handle('data2', 2)->then([$spy, 'resolve']),
            $mutationLimiter->handle('data3', 3)->then([$spy, 'resolve']),
        );

        $spyObject->resolve('data1')->shouldHaveBeenCalledTimes(2);
        $spyObject->resolve('data2')->shouldHaveBeenCalledOnce();
        $spyObject->resolve('data3')->shouldHaveBeenCalledOnce();
    }

    /**
     * @test
     */
    public function shouldLimitHandlerExacutionForTheSameId()
    {
        $loop = $this->getLoop();

        $count = 0;

        $mutationLimiter = new MutationLimiter(
            function() use($loop, &$count) {
                $deferred = new Deferred();

                $count++;

                $loop->addTimer(0.01, function() use ($deferred, &$count) {
                    $deferred->resolve($count);
                });

                return $deferred->promise();
            },
            function () {
                return 1;
            },
            10,
        );

        $this->waitForPromises(
            $mutationLimiter->handle('data')->then(function($count) {
                $this->assertEquals(1, $count);
            }),
            $mutationLimiter->handle('data')->then(function($count) {
                $this->assertEquals(2, $count);
            }),
            $mutationLimiter->handle('data')->then(function($count) {
                $this->assertEquals(3, $count);
            }),
        );
    }

    /**
     * @test
     */
    public function shouldLimitHandlerExecutionOnlyForTheSameId()
    {
        $loop = $this->getLoop();

        $count = 0;

        $mutationLimiter = new MutationLimiter(
            function($index) use ($loop, &$count) {
                $deferred = new Deferred();

                $count++;

                $loop->addTimer(0.01, function() use ($deferred, $count) {
                    $deferred->resolve($count);
                });

                return $deferred->promise();
            },
            function($index) {
                return $index;
            },
            10,
        );

        $this->waitForPromises(
            $mutationLimiter->handle(1)->then(function($count) {
                $this->assertEquals(1, $count);
            }),
            $mutationLimiter->handle(1)->then(function($count) {
                $this->assertEquals(3, $count);
            }),
            $mutationLimiter->handle(2)->then(function($count) {
                $this->assertEquals(2, $count);
            })
        );
    }

    /**
     * @test
     */
    public function shouldCallRejectCallbackOnReject()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $mutationLimiter = new MutationLimiter(
            function() {
                return reject(new \Exception('reject message'));
            },
            function() {
                return 1;
            },
            10,
        );

        $mutationLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->reject(new \Exception('reject message'))->shouldHaveBeenCalledOnce();
    }

    /**
     * @test
     */
    public function shouldCallRejectCallbackOnError()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $mutationLimiter = new MutationLimiter(
            function() {
                throw new \Exception('exception message');
            },
            function() {
                return 1;
            },
            10,
        );

        $mutationLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->reject(new \Exception('exception message'))->shouldHaveBeenCalledOnce();
    }

    /**
     * @test
     */
    public function shouldRejectOnUnsuportedIdType()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $mutationLimiter = new MutationLimiter(
            function() {
                return 'resovled';
            },
            function() {
                return [];
            },
            10
        );

        $mutationLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->reject(Argument::type(UnexpectedValueException::class))->shouldHaveBeenCalledOnce();
    }
}