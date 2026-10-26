<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * `getrusage`'s `ru_maxrss`, the most resident memory a process held, which
 * macOS gives in bytes, and Linux and the BSDs in kilobytes.
 */
final readonly class MaxRss
{
    /** The operating system family, as `PHP_OS_FAMILY` names it, that counts `ru_maxrss` in bytes. */
    private const string IN_BYTES = 'Darwin';

    /** `ru_maxrss` in bytes, on the operating system family `PHP_OS_FAMILY` names. */
    public static function bytes(int $maximum, string $family): int
    {
        return $family === self::IN_BYTES ? $maximum : $maximum * MemoryUnit::Kilobytes->bytes();
    }

    /**
     * The peak `ru_maxrss` gives, on the operating system family `PHP_OS_FAMILY`
     * names; none where it gives none, as a process that waited for no child does.
     */
    public static function peak(int $maximum, string $family): MemoryCap|NotGiven
    {
        return $maximum > 0 ? MemoryCap::atLeast(self::bytes($maximum, $family)) : NotGiven::value();
    }
}
