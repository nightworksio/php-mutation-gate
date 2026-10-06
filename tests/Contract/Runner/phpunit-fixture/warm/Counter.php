<?php

declare(strict_types=1);

namespace Warm;

final class Counter
{
    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public static function next(int $n): int
    {
        return $n + 1;
    }
}
