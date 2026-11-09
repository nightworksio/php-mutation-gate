<?php

declare(strict_types=1);

namespace Library;

/**
 * Three sums for a split stream to judge: one its stream kills, one whose
 * mutant never ends, which the child's limit stops, and one only a test that
 * holds a socket reaches, which no stream forks in.
 */
final readonly class Streamed
{
    public function added(int $one, int $other): int
    {
        return $one + $other;
    }

    public function counted(int $times): int
    {
        $counted = 0;

        for ($at = 0; $at < $times; $at++) {
            $counted += 1;
        }

        return $counted;
    }

    public function doubled(int $number): int
    {
        return $number * 2;
    }
}
