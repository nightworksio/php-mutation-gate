<?php

declare(strict_types=1);

namespace Library;

/**
 * A sum a fast test covers without telling its mutant apart and a slow one,
 * in a file of its own, tells apart, so a second pass of split streams
 * judges it; the slow test stalls in a stream where the run asks it to.
 */
final readonly class Waited
{
    public function doubled(int $amount): int
    {
        return $amount + $amount;
    }
}
