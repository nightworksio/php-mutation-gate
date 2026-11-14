<?php

declare(strict_types=1);

namespace Library;

/**
 * Sums for a split stream to judge: one its stream kills, one whose mutant
 * never ends, which the child's limit stops, three only a test reaches that
 * holds a socket, a writable file or a file-backed SQLite database, which no
 * stream forks in, and one a test reaches that reads a file, which it does.
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

    public function tripled(int $number): int
    {
        return $number * 3;
    }

    public function quadrupled(int $number): int
    {
        return $number * 4;
    }

    public function shifted(int $number): int
    {
        return $number + 10;
    }
}
