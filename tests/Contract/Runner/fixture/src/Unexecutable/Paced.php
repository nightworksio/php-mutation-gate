<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A constant a loop steps by, so a mutant that makes it nought never ends. */
final class Paced
{
    public const STEP = 1;

    public static function count(int $to): int
    {
        $counted = 0;

        for ($at = 0; $at < $to; $at += self::STEP) {
            $counted++;
        }

        return $counted;
    }
}
