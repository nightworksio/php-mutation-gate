<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/**
 * Values on lines coverage cannot see run, each read another way: through an
 * alias, only through self:: in a covered method, through the class that
 * inherits or implements it, as a property's default, and not at all.
 */
final class Rates extends BaseRate implements Rated
{
    public const ALIASED = 7;

    public const INTERNAL = 11;

    public const UNREAD = 29;

    public static int $count = 13;

    public int $cents = 17;

    public function internal(): int
    {
        return self::INTERNAL;
    }
}
