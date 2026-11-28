<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;

/**
 * How many parts a pass cuts its test files into (ADR-0188).
 *
 * @internal
 */
final readonly class PartCounts
{
    /**
     * The parts for the files whose tests read their coverage, then for the
     * others: this many for each process, fewer than one counting as one,
     * the readers' processes theirs and what is left of these processes the
     * others', which get one at least.
     *
     * @return array{int, int}
     */
    public static function of(int $processes, int $readers, int $perProcess): array
    {
        $depth = max(1, $perProcess);

        return [$readers * $depth, max(1, $processes - $readers) * $depth];
    }
}
