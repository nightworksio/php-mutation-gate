<?php

declare(strict_types=1);

namespace Library;

use function fwrite;

/** Counters whose loops never end under a mutant: one says nothing, the other keeps printing. */
final readonly class Hang
{
    public function quietly(int $count): int
    {
        while ($count > 0) {
            $count--;
        }

        return $count;
    }

    public function loudly(int $count): int
    {
        while ($count > 0) {
            fwrite(STDOUT, "counting\n");
            fwrite(STDERR, "counting\n");
            $count--;
        }

        return $count;
    }

    public function sum(int $first, int $second): int
    {
        return $first + $second;
    }
}
