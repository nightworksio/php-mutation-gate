<?php

declare(strict_types=1);

namespace Library;

/**
 * A sum a test tells apart only in a PHP process it starts: in its own
 * process it adds two zeros, which the mutant adds alike.
 */
final readonly class Spawned
{
    public function added(int $one, int $other): int
    {
        return $one + $other;
    }
}
