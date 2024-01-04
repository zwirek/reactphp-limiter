<?php

namespace utils;

interface SpyInterface
{
    public function resolve();

    public function reject();

    public function handler();
}