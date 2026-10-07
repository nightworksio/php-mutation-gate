<?php

declare(strict_types=1);

namespace Library;

final readonly class Stall
{
    public function drain(int $amount): int
    {
        while ($amount !== 0) {
            $amount--;
        }

        return $amount;
    }
}
