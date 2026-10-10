<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * `getrusage`'s `ru_maxrss`, the most resident memory a process held, which
 * macOS gives in bytes, and Linux and the BSDs in kilobytes.
 */
final readonly class MaxRss
{
    /** `ru_maxrss` in bytes, on the operating system family `PHP_OS_FAMILY` names. */
    public static function bytes(int $maximum, string $family): int
    {
        return $family === OsFamily::Darwin->value ? $maximum : $maximum * MemoryUnit::Kilobytes->bytes();
    }
}
