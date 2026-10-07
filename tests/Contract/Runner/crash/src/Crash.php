<?php

declare(strict_types=1);

namespace Library;

final readonly class Crash
{
    public function settle(int $code): int
    {
        if ($code > 0) {
            exit(3);
        }

        return $code;
    }
}
