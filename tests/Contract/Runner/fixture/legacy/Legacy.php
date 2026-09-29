<?php

declare(strict_types=1);

namespace Legacy;

final readonly class Legacy
{
    public function decrement(int $amount): int
    {
        return $amount - 1;
    }
}
