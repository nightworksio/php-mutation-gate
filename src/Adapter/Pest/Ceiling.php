<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Format\Bytes;

/**
 * The longest argument, in bytes, the patch passes a mutant's process: the
 * `--filter` its covering tests select, and the test files they need, joined
 * by spaces. The operating system bounds a command line in bytes, and the
 * patched plugin counts the filter with `strlen()`, so every side measures
 * bytes.
 */
final readonly class Ceiling
{
    public const int BYTES = 100000;

    /** Whether the argument is shorter than the most bytes it may take. */
    public static function admits(string $argument, int $most = self::BYTES): bool
    {
        return Bytes::length($argument) < $most;
    }
}
