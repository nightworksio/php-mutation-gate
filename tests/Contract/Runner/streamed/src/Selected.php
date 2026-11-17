<?php

declare(strict_types=1);

namespace Library;

/**
 * A product whose test file also holds a test that covers nothing of it and
 * ends any stream that runs it, so only a stream that runs the covering tests
 * alone judges its mutant.
 */
final readonly class Selected
{
    public function quadrupled(int $amount): int
    {
        return $amount * 4;
    }
}
