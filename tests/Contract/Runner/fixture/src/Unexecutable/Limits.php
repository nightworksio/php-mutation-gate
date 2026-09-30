<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A constant read only through a variable class, which two test files cover. */
final class Limits
{
    public const CEILING = 7;

    public static function of(string $class): int
    {
        return $class::CEILING;
    }
}
