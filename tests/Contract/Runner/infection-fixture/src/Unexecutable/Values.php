<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/**
 * Values outside any function, which Infection never mutates, and a
 * parameter's default, in a signature, which it does.
 */
#[Weight(5)]
final class Values
{
    public const RATE = 7;

    public static int $count = 13;

    public int $cents = 17;

    public function discount(int $percent = 10): int
    {
        return $percent;
    }
}
