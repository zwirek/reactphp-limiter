<?php
declare(strict_types=1);

namespace Zwirek\Limiter;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SplQueue;
use Zwirek\Limiter\Exception\InvalidArgumentException;
use Zwirek\Limiter\Exception\OverflowException;
use function React\Promise\reject;

final class TimeWindowLimiter implements Limiter
{
    private int $limit;
    private int $pending = 0;
    private int $windowPending = 0;
    private float $window;
    private $handler;
    private int $overflow;
    private ?LoopInterface $loop;
    private SplQueue $queue;
    private ?TimerInterface $timer = null;

    public function __construct(
        int $limit,
        int $milliseconds,
        callable $handler,
        int $overflow = 0,
        ?LoopInterface $loop = null,
    ) {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Invalid limit. Limit must be greater than 0');
        }

        if ($milliseconds <= 0) {
            throw new InvalidArgumentException('Invalid window. Window must be greater than 0');
        }

        if ($overflow < 0) {
            throw new InvalidArgumentException('Invalid overflow limit. Overflow must be greater or equal to 0');
        }

        $this->limit = $limit;
        $this->window = $milliseconds * 0.001;
        $this->handler = $handler;
        $this->overflow = $overflow;
        $this->loop = $loop ?? Loop::get();
        $this->queue = new SplQueue();
        $this->queue->setIteratorMode(\SplDoublyLinkedList::IT_MODE_DELETE);
    }

    public function handle(...$arguments): PromiseInterface
    {
        $deferred = new Deferred();

        if ($this->isOverflowed()) {
            $deferred->reject(new OverflowException('Too many waiting jobs'));

            return $deferred->promise();
        }

        $job = [
            'deferred' => $deferred,
            'arguments' => $arguments,
        ];

        $this->queue->enqueue($job);

        $this->dequeue();

        return $deferred->promise()
            ->then(
                function($resolve) {
                    $this->pending--;

                    return $resolve;
                },
                function($reject) {
                    $this->pending--;

                    return reject($reject);
                }
            );
    }

    private function dequeue(): void
    {
        $this->setTimer();

        if ($this->queue->isEmpty()) {
            return;
        }

        if ($this->windowPending >= $this->limit) {
            return;
        }

        $this->windowPending++;
        $this->pending++;

        /** @var Deferred $deferred */
        [
            'deferred' => $deferred,
            'arguments' => $arguments,
        ] = $this->queue->dequeue();

        try {
            $result = ($this->handler)(...$arguments);
            $deferred->resolve($result);
        } catch (\Exception $exception) {
            $deferred->reject($exception);
        }

        $this->dequeue();
    }

    private function setTimer(): void
    {
        if (null !== $this->timer) {
            return;
        }

        $this->timer = $this->loop->addTimer($this->window, function() {
            $this->windowPending = 0;
            $this->timer = null;

            if (!$this->queue->isEmpty()) {
                $this->dequeue();
            }
        });
    }

    private function isOverflowed(): bool
    {
        return $this->overflow > 0 && count($this->queue) + $this->pending >= $this->overflow;
    }
}
