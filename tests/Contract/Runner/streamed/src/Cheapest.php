<?php

declare(strict_types=1);

namespace Library;

/**
 * A product each of whose mutants a slow test and a fast one cover: the slow
 * one alone tells apart the first, neither the second, and both the third,
 * so a stream that forks first at the cheapest covering test still finds the
 * slow kill, and names the fast one where both kill.
 */
final readonly class Cheapest
{
    public function doubled(int $amount): int
    {
        return $amount * 2;
    }

    public function tripled(int $amount): int
    {
        return $amount * 3;
    }

    public function quadrupled(int $amount): int
    {
        return $amount * 4;
    }
}
