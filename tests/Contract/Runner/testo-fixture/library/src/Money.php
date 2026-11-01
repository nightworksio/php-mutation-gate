<?php

declare(strict_types=1);

namespace Library;

final readonly class Money
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function isLarge(int $amount): bool
    {
        return $amount > 100;
    }

    public function unused(int $amount): int
    {
        return $amount - 1;
    }

    public function drain(int $amount): int
    {
        while ($amount !== 0) {
            $amount--;
        }

        return $amount;
    }
}
