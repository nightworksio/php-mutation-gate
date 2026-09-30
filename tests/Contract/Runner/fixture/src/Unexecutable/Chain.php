<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A value read through four declarations, one more than the scan follows, and a covered method. */
final class Chain
{
    public const FIRST = 2;

    public const SECOND = self::FIRST;

    public const THIRD = self::SECOND;

    public const FOURTH = self::THIRD;

    public const FIFTH = self::FOURTH;

    public function fifth(): int
    {
        return self::FIFTH;
    }
}
