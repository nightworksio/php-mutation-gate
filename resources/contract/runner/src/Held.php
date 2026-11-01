<?php

declare(strict_types=1);

namespace Library;

final readonly class Held
{
    public function double(int $amount): int
    {
        return $amount + $amount;
    }
}
