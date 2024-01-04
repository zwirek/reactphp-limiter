<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use React\Promise\Deferred;
use utils\SpyInterface;
use utils\TestLoopTrait;
use Zwirek\Limiter\Exception\InvalidArgumentException;
use Zwirek\Limiter\Exception\OverflowException;
use Zwirek\Limiter\RateLimiter;
use function React\Promise\reject;

/**
 * @covers \Zwirek\Limiter\RateLimiter
 */
class RateLimiterTest extends TestCase
{
    use ProphecyTrait;
    use TestLoopTrait;

    /**
     * @test
     */
    public function shouldExecuteSyncHandlerForAllJobs()
    {
        $rateLimiter = new RateLimiter(1, function() {
            return 'sync_return';
        });

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $rateLimiter->handle('data')->then([$spy, 'resolve']);
        $rateLimiter->handle('data')->then([$spy, 'resolve']);
        $rateLimiter->handle('data')->then([$spy, 'resolve']);

        $spyObject->resolve('sync_return')->shouldBeCalledTimes(3);
    }

    /**
     * @test
     */
    public function shouldExecuteAsyncHandlerForAllJobs()
    {
        $loop = $this->getLoop();

        $rateLimiter = new RateLimiter(3, function() use($loop) {
            $deferred = new Deferred();

            $loop->addTimer(0.01, function() use ($deferred) {
                $deferred->resolve('async_return');
            });

            return $deferred->promise();
        });

        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $this->waitForPromises(
            $rateLimiter->handle('data')->then([$spy, 'resolve']),
            $rateLimiter->handle('data')->then([$spy, 'resolve']),
            $rateLimiter->handle('data')->then([$spy, 'resolve']),
        );

        $spyObject->resolve('async_return')->shouldBeCalledTimes(3);
    }

    /**
     * @test
     */
    public function shouldExecuteAsyncHandlerWithLimit()
    {
        $loop = $this->getLoop();

        $count = 0;

        $rateLimiter = new RateLimiter(2, function() use($loop, &$count) {
            $deferred = new Deferred();

            $count++;

            $loop->addTimer(0.01, function() use ($deferred, &$count) {
                $deferred->resolve($count);
                $count--;
            });

            return $deferred->promise();
        });

        $this->waitForPromises(
            $rateLimiter->handle('data')->then(function($count) {
                $this->assertEquals(2, $count);
            }),
            $rateLimiter->handle('data')->then(function($count) {
                $this->assertEquals(2, $count);
            }),
            $rateLimiter->handle('data')->then(function($count) {
                $this->assertEquals(1, $count);
            }),
        );
    }

    /**
     * @test
     */
    public function shouldCallResolveCallbackOnNoResponseFromHandler()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $rateLimiter = new RateLimiter(1, function() {

        });

        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->resolve(null)->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function shouldCallRejectCallback()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $rateLimiter = new RateLimiter(1, function() {
            return reject(new \Exception('rejected'));
        });

        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->reject(new \Exception('rejected'))->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function shouldCallRejectCallbackWhenHandlerThrowException()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $rateLimiter = new RateLimiter(1, function() {
            throw new \Exception('Handler exception');
        });

        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->reject(new \Exception('Handler exception'))->shouldHaveBeenCalled();
    }

    /**
     * @test
     * @dataProvider argumentsDataProvider
     */
    public function shouldPassArgumentsIntoHandler($arguments)
    {
        $spyObject = $this->prophesize(SpyInterface::class);

        $rateLimiter = new RateLimiter(1, [$spyObject->reveal(), 'handler']);
        $rateLimiter->handle(...$arguments);

        $spyObject->handler(...$arguments)->shouldHaveBeenCalledOnce();
    }

    public function argumentsDataProvider(): array
    {
        return [
            'one argument' => [
                ['one']
            ],
            'two arguments' => [
                ['first', 'second']
            ]
        ];
    }

    /**
     * @test
     * @dataProvider invalidLimitDataProvider
     */
    public function shouldThrowExceptionOnInvalidLimit(int $limit)
    {
        $this->expectException(InvalidArgumentException::class);
        new RateLimiter($limit, function() {});
    }

    public function invalidLimitDataProvider(): array
    {
        return [
            'negative number' => [-1],
            'zero' => [0]
        ];
    }

    /**
     * @test
     */
    public function shouldThrowExceptionOnInvalidOverflow()
    {
        $this->expectException(InvalidArgumentException::class);
        new RateLimiter(1, function() {}, -1);
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenOverflowIsLessThanLimit()
    {
        $this->expectException(InvalidArgumentException::class);
        new RateLimiter(10, function() {}, 5);
    }

    /**
     * @test
     */
    public function shouldRejectWhenWaitingJobsQueueOverflow()
    {
        $spyObject = $this->prophesize(SpyInterface::class);

        $spy = $spyObject->reveal();

        $rateLimiter = new RateLimiter(1, function() {
            return (new Deferred())->promise();
        }, 2);

        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);
        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);
        $rateLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']);

        $spyObject->resolve(Argument::any())->shouldNotHaveBeenCalled();
        $spyObject->reject(Argument::type(OverflowException::class))->shouldHaveBeenCalledOnce();
    }
}