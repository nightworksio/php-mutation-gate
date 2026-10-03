<?php

declare(strict_types=1);

namespace Library;

final readonly class Reach
{
    public static function amount(int $amount): int
    {
        return $amount + 1;
    }
}
