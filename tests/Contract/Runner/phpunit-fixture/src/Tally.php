<?php

declare(strict_types=1);

namespace Quirks;

use function str_repeat;

/** The class the quirks of a project's tests run against, apart from the library every runner mutates. */
final class Tally
{
    private int $counted = 0;

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /** Counts a call, which no test reads. */
    public function count(): void
    {
        $this->counted = $this->counted + 1;
    }

    public function drain(int $amount): int
    {
        while ($amount !== 0) {
            $amount--;
        }

        return $amount;
    }

    /** A width of padding, one space whatever it is asked for. */
    public function padded(int $width): string
    {
        return str_repeat(' ', $width + (-$width) + 1);
    }
}
