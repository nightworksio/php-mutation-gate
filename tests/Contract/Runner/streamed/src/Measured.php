<?php

declare(strict_types=1);

namespace Library;

/**
 * A product whose test asks whether pcov is on, so it passes only where pcov
 * is on in the process that runs it.
 */
final readonly class Measured
{
    public function doubled(int $amount): int
    {
        return $amount + $amount;
    }
}
