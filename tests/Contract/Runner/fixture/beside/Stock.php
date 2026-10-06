<?php

declare(strict_types=1);

namespace Beside;

final readonly class Stock
{
    public function left(int $held, int $sold): int
    {
        return $held - $sold;
    }
}
