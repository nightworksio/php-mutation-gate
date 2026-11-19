<?php

declare(strict_types=1);

namespace Library;

/**
 * A product whose likely killer holds a writable file, so no stream forks its
 * mutant there, and which an earlier test also tells apart.
 */
final readonly class Fallen
{
    public function tripled(int $amount): int
    {
        return $amount * 3;
    }
}
