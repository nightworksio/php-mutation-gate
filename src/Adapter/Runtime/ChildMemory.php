<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function getrusage;
use function is_array;
use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
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

    public function peak(): MemoryCap|NotGiven
    {
        $usage = getrusage(self::CHILDREN);

        return is_array($usage) && is_int($usage[self::MAXIMUM])
            ? MaxRss::peak($usage[self::MAXIMUM], PHP_OS_FAMILY)
            : NotGiven::value();
    }
}
