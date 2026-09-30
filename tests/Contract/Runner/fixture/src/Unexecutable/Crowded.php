<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A constant read only through a variable class, in a file eleven test files cover. */
final class Crowded
{
    public const PEAK = 9;

    public function touch(): int
    {
        return 1;
    }

    public static function peak(string $class): int
    {
        return $class::PEAK;
    }
}
