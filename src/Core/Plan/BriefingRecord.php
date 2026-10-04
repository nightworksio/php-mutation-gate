<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixRecord;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;

/**
 * A briefing as the plan file holds it: `peak`, the bytes the unmutated
 * suite's largest process held, where the plan measured them, and `matrix`,
 * `full` where the run records every killer of each mutant. A file without
 * `peak` measured none, and one without `matrix` records first killers.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{peak?: int, matrix?: string}
 */
final readonly class BriefingRecord
{
    private const string PEAK = 'peak';

    /** @return Written */
    public static function of(Briefing $briefing): array
    {
        $peak = $briefing->peak();

        return [
            ...$peak instanceof MemoryCap ? [self::PEAK => $peak->bytes()] : [],
            ...MatrixRecord::of($briefing->matrix()),
        ];
    }

    /**
     * The briefing a plan file holds.
     *
     * @throws NotInShape
     */
    public static function read(Node $file): Briefing
    {
        $peak = $file->field(self::PEAK);

        return Briefing::standard()
            ->weighing($peak->isPresent() ? WrittenBytes::read($peak) : NotGiven::value())
            ->recording(MatrixRecord::read($file->field(MatrixRecord::FIELD)));
    }
}
