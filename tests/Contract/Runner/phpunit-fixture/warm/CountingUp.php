<?php

declare(strict_types=1);

namespace Warm;

final class Counter
{
    public static function add(int $a, int $b): int
    {
        for ($i = 0; $i < $b; $i = $i + 1) {
            $a = $a + 1;
        }

        return $a;
    }

    public static function next(int $n): int
    {
        return $n;
    }
}
