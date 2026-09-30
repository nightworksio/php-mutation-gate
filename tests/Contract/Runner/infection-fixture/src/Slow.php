<?php

declare(strict_types=1);

namespace Library;

final readonly class Slow
{
    public function reduce(int $amount): int
    {
        return $amount - 2;
    }
}
