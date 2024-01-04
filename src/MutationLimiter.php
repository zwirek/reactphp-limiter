<?php

namespace Zwirek\Limiter;

use React\Promise\PromiseInterface;
use Zwirek\Limiter\Exception\UnexpectedValueException;
use function React\Promise\reject;

final class MutationLimiter implements Limiter
{
    private array $limiters = [];

    /**
     * @var callable
     */
    private $handler;
    /**
     * @var callable
     */
    private $idResolver;
    private int $overflow;

    public function __construct(
        callable $handler,
        callable $idResolver,
        int $overflow = 0,
    ) {
        $this->handler = $handler;
        $this->idResolver = $idResolver;
        $this->overflow = $overflow;
    }

    public function handle(...$arguments): PromiseInterface
    {
        $identifier = ($this->idResolver)(...$arguments);

        if (!$this->validateIdentifier($identifier)) {
            return reject(new UnexpectedValueException(
                'Invalid identifier type'
            ));
        }

        if (!array_key_exists($identifier, $this->limiters)) {
            $this->limiters[$identifier] = [
                'limiter' => new RateLimiter(1, $this->handler, $this->overflow),
                'pending' => 0,
            ];
        }

        $limiterInfo =& $this->limiters[$identifier];
        $limiterInfo['pending']++;

        /** @var RateLimiter $limiter */
        $limiter = $limiterInfo['limiter'];

        return $limiter->handle(...$arguments)
            ->then(function($result) use ($identifier) {
                $this->processed($identifier);

                return $result;
            }, function($result) use ($identifier) {
                $this->processed($identifier);

                return reject($result);
            });
    }

    private function processed($identifier): void
    {
        $limiterInfo =& $this->limiters[$identifier];

        $limiterInfo['pending']--;

        if ($limiterInfo['pending'] === 0) {
            unset($this->limiters[$identifier]);
        }
    }

    private function validateIdentifier($identifier): bool
    {
        return is_numeric($identifier) || is_string($identifier);
    }
}
