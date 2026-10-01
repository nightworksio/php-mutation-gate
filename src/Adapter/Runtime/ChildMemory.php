<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function getrusage;
use function is_array;
use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\PeakMemory;

/**
 * The most resident memory any process this one started and waited for has
 * used: `getrusage`'s `ru_maxrss` over its children, which counts each
 * descendant a child waited for in turn. macOS gives it in bytes, Linux and
 * the BSDs in kilobytes.
 */
final readonly class ChildMemory implements PeakMemory
{
    /** `RUSAGE_CHILDREN`: the processes this one waited for, rather than itself. */
    private const int CHILDREN = 1;

    private const string MAXIMUM = 'ru_maxrss';

    /** The operating system family, as `PHP_OS_FAMILY` names it, that counts `ru_maxrss` in bytes. */
    private const string IN_BYTES = 'Darwin';

    public function peak(): MemoryCap|NotGiven
    {
        $usage = getrusage(self::CHILDREN);
        $maximum = is_array($usage) && is_int($usage[self::MAXIMUM]) ? $usage[self::MAXIMUM] : 0;

        return $maximum > 0 ? MemoryCap::atLeast(self::bytes($maximum, PHP_OS_FAMILY)) : NotGiven::value();
    }

    /** `ru_maxrss` in bytes, on the operating system family `PHP_OS_FAMILY` names. */
    public static function bytes(int $maximum, string $family): int
    {
        return $family === self::IN_BYTES ? $maximum : $maximum * MemoryUnit::Kilobytes->bytes();
    }
}
