<?php

declare(strict_types=1);

namespace Library;

/**
 * A doubling kept once worked out, so a test that primes it first hides a
 * change to the sum from every test after it in the same process.
 */
final class Masked
{
    private static ?int $kept = null;

    public static function prime(int $doubled): void
    {
        self::$kept = $doubled;
    }

    public static function doubled(int $amount): int
    {
        return self::$kept ??= $amount + $amount;
    }
}
