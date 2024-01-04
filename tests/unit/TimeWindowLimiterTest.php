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
use Zwirek\Limiter\TimeWindowLimiter;
use function React\Promise\reject;

/**
 * @covers \Zwirek\Limiter\TimeWindowLimiter
 */
class TimeWindowLimiterTest extends TestCase
{
    use ProphecyTrait;
    use TestLoopTrait;

    /**
     * @test
     */
    public function shouldExecuteSyncHandler()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $timeWindowLimiter = new TimeWindowLimiter(
            4,
            10,
            function() {
                return true;
            }
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
        );

        $spyObject->resolve(true)->shouldHaveBeenCalledTimes(4);
    }

    /**
     * @test
     */
    public function shouldExecuteAsyncHandler()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $loop = $this->getLoop();

        $timeWindowLimiter = new TimeWindowLimiter(
            4,
            10,
            function() use ($loop) {
                $deferred = new Deferred();

                $loop->addTimer(0.01, function() use ($deferred) {
                    $deferred->resolve('resolved');
                });

                return $deferred->promise();
            }
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
        );

        $spyObject->resolve('resolved')->shouldHaveBeenCalledTimes(4);
    }

    /**
     * @test
     */
    public function shouldExececuteAsyncHandlerWithLimit()
    {
        $loop = $this->getLoop();

        $count = 0;

        $this->setLoopTimeout(2);

        $timeWindowLimiter = new TimeWindowLimiter(
            2,
            20,
            function() use ($loop, &$count) {
                $deferred = new Deferred();

                $count++;

                $loop->addTimer(0.01, function() use ($deferred, &$count) {
                    $deferred->resolve($count);
                });

                return $deferred->promise();
            }
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then(function($count) {
                $this->assertEquals(2, $count);
            }),
            $timeWindowLimiter->handle('data')->then(function($count) {
                $this->assertEquals(2, $count);
            }),
            $timeWindowLimiter->handle('data')->then(function($count) {
                $this->assertEquals(3, $count);
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

        $timeWindowLimiter = new TimeWindowLimiter(
            1,
            10,
            function() {}
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'])
        );

        $spyObject->resolve(null)->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function shouldCallRejectCallback()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $timeWindowLimiter = new TimeWindowLimiter(
            1,
            10,
            function() {
                return reject(new \Exception('rejected'));
            }
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject'])
        );

        $spyObject->reject(new \Exception('rejected'))->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function shouldCallRejectCallbackOnHandlerError()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $timeWindowLimiter = new TimeWindowLimiter(
            1,
            10,
            function() {
                throw new \Exception('handler exception');
            }
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject'])
        );

        $spyObject->reject(new \Exception('handler exception'))->shouldHaveBeenCalled();
    }

    /**
     * @test
     * @dataProvider argumentsDataProvider
     */
    public function shouldPassArgumentsIntoHandler($arguments)
    {
        $spyObject = $this->prophesize(SpyInterface::class);

        $timeWindowLimiter = new TimeWindowLimiter(
            1,
            10,
            [$spyObject->reveal(), 'handler']
        );

        $timeWindowLimiter->handle(...$arguments);

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
    public function shouldThrowExcaptionOnInvalidLimit(int $limit)
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeWindowLimiter(
            $limit,
            1,
            function() {

            }
        );
    }

    public function invalidLimitDataProvider()
    {
        return [
            'negative' => [-1],
            'zero' => [0],
        ];
    }

    /**
     * @test
     * @dataProvider invalidWindowDataProvider
     */
    public function shouldThrowExceptionWhenWindowIsInvalid(int $window)
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeWindowLimiter(
            1,
            $window,
            function() {

            }
        );
    }

    public function invalidWindowDataProvider()
    {
        return [
            'negative' => [-1],
            'zero' => [0],
        ];
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenOverflowIsInvalid()
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeWindowLimiter(
            1,
            1,
            function() {},
            -1
        );
    }

    /**
     * @test
     */
    public function shouldRejectWhenOverflowLimitReached()
    {
        $spyObject = $this->prophesize(SpyInterface::class);
        $spy = $spyObject->reveal();

        $loop = $this->getLoop();

        $timeWindowLimiter = new TimeWindowLimiter(
            10,
            10,
            function() use ($loop) {
                $deferred = new Deferred();

                $loop->addTimer(0.01, function() use ($deferred) {
                    $deferred->resolve('resolved');
                });

                return $deferred->promise();
            },
            1
        );

        $this->waitForPromises(
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
            $timeWindowLimiter->handle('data')->then([$spy, 'resolve'], [$spy, 'reject']),
        );

        $spyObject->resolve(Argument::any())->shouldHaveBeenCalledOnce();
        $spyObject->reject(Argument::type(OverflowException::class))->shouldHaveBeenCalledTimes(3);
    }
}
