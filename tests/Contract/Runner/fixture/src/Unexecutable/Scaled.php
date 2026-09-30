<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A closure's parameter default, on a line of its own that coverage cannot see run. */
final class Scaled
{
    public function scale(): int
    {
        $scale = static function (
            int $factor = 3,
        ): int {
            return $factor;
        };

        return $scale();
    }
}
