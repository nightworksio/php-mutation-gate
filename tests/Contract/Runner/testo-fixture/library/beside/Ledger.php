<?php

declare(strict_types=1);

namespace Beside;

final readonly class Ledger
{
    public function balance(int $in, int $out): int
    {
        return $in - $out;
    }
}
