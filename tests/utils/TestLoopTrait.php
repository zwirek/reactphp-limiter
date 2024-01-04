<?php

namespace utils;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;

trait TestLoopTrait
{
    private $eventLoopTimers = [];
    private $loopTimeout = 1;

    public function getLoop(): LoopInterface
    {
        return Loop::get();
    }

    public function waitForLoop(): void
    {
        $this->stopTimers();
        $this->startTimers();

        Loop::run();
    }

    public function waitForPromises(PromiseInterface ...$promises): void
    {
        $exception = null;

        $this->getLoop()->futureTick(function() use (&$promises, &$exception) {
            if (method_exists(PromiseInterface::class, 'catch')) {
                all($promises)
                    ->catch(function($e) use (&$exception) {
                        $exception = $e;
                    })
                    ->finally(function() {
                        $this->stopTimers();
                    });
            } else {
                all($promises)
                    ->otherwise(function($e) use (&$exception) {
                        $exception = $e;
                    })
                    ->always(function() {
                        $this->stopTimers();
                    });
            }
        });

        $this->waitForLoop();

        if ($exception instanceof ExpectationFailedException) {
            throw $exception;
        }
    }

    public function setLoopTimeout(int $timeout): void
    {
        $this->loopTimeout = $timeout;
    }

    /**
     * @before
     */
    protected function setUpTestLoop(): void
    {
        $this->startTimers();
    }

    /**
     * @after
     */
    protected function tearDownTestLoop(): void
    {
        $this->stopTimers();
        $this->loopTimeout = 1;
    }

    private function startTimers(): void
    {
        $loop = Loop::get();

        $this->eventLoopTimers[] = $loop->addTimer($this->loopTimeout, function() use($loop) {
            $loop->futureTick(function() use ($loop) {
                $loop->stop();
            });

            TestCase::fail('Test event loop timeout occurred');

        });
    }

    private function stopTimers(): void
    {
        foreach ($this->eventLoopTimers as $loopTimer) {
            Loop::cancelTimer($loopTimer);
        }

        $this->eventLoopTimers = [];
    }
}
