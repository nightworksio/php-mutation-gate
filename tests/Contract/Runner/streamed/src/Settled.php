<?php

declare(strict_types=1);

namespace Library;

/**
 * A sum whose one test tells its mutant apart, in a file no other product's
 * test shares, so the first pass of split streams settles it.
 */
final readonly class Settled
{
    public function added(int $amount): int
    {
        return $amount + 1;
    }
}
