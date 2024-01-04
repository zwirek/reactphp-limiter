<?php

namespace Zwirek\Limiter;

use React\Promise\PromiseInterface;

interface Limiter
{
    public function handle(mixed ...$arguments): PromiseInterface;
}