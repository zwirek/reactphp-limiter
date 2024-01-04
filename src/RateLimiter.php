<?php
declare(strict_types=1);

namespace Zwirek\Limiter;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SplDoublyLinkedList;
use SplQueue;
use Zwirek\Limiter\Exception\InvalidArgumentException;
use Zwirek\Limiter\Exception\OverflowException;
use function React\Promise\reject;

final class RateLimiter implements Limiter
{
    private int $limit;
    private $handler;
    private int $overflow;
    private SplQueue $queue;
    private int $pending = 0;

    public function __construct(
        int $limit,
        callable $handler,
        int $overflow = 0,
    ) {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Invalid limit. Limit must be greater than 0');
        }

        if ($overflow < 0) {
            throw new InvalidArgumentException('Invalid overflow. Overflow must be greater or equal than 0');
        }

        if ($overflow > 0 && $limit > $overflow) {
            throw new InvalidArgumentException('Invalid limit. Limit must be greater than overflow.');
        }

        $this->limit = $limit;
        $this->handler = $handler;
        $this->overflow = $overflow;

        $this->queue = new SplQueue();
        $this->queue->setIteratorMode(SplDoublyLinkedList::IT_MODE_DELETE);
    }

    public function handle(...$arguments): PromiseInterface
    {
        $deferred = new Deferred();

        $job = [
            'deferred' => $deferred,
            'arguments' => $arguments,
        ];

        if ($this->isOverflowed()) {
            $deferred->reject(new OverflowException('Too many waiting jobs'));

            return $deferred->promise();
        }

        $this->queue->enqueue($job);

        $this->dequeue();

        return $this->returnPromise($deferred);
    }

    private function dequeue(): void
    {
        if ($this->queue->isEmpty()) {
            return;
        }

        if ($this->pending >= $this->limit) {
            return;
        }

        $this->pending++;

        $item = $this->queue->dequeue();

        /** @var Deferred $deferred */
        [
            'deferred' => $deferred,
            'arguments' => $arguments,
        ] = $item;

        try {
            $result = ($this->handler)(...$arguments);
            $deferred->resolve($result);
        } catch (\Exception $exception) {
            $deferred->reject($exception);
        }
    }

    private function returnPromise(Deferred $deferred): PromiseInterface
    {
        return $deferred->promise()
            ->then(function($result) {
                $this->pending--;

                $this->dequeue();

                return $result;
            }, function($result) {
                $this->pending--;

                $this->dequeue();

                return reject($result);
            });
    }

    private function isOverflowed(): bool
    {
        return $this->overflow > 0 && count($this->queue) + $this->pending >= $this->overflow;
    }
}
