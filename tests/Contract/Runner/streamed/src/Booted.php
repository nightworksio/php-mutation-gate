<?php

declare(strict_types=1);

namespace Library;

/** A sum the application's boot runs, which a stream that boots once cannot fork at. */
final readonly class Booted
{
    public function readied(int $services): int
    {
        return $services + 1;
    }
}
