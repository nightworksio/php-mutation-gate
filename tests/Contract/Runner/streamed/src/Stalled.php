<?php

declare(strict_types=1);

namespace Library;

/**
 * A product whose test file also holds a test that reaches it, tells none of
 * its mutants apart, and stalls in any stream that runs it, so a stream
 * judges its mutant only once it stops the stalled test and runs the next.
 */
final readonly class Stalled
{
    public function raised(int $amount): int
    {
        return $amount + 2;
    }
}
