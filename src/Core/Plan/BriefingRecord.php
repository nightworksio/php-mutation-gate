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
 * suite's largest process held, where the plan measured them, `matrix`,
 * `full` where the run records every killer of each mutant, and `security`,
 * `true` where the run makes mutants with the security mutators alone. A file
 * without `peak` measured none, one without `matrix` records first killers,
 * and one without `security` makes mutants with every mutator the run turns
 * on.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{peak?: int, matrix?: string, security?: true}
 */
final readonly class BriefingRecord
{
    private const string PEAK = 'peak';

    private const string SECURITY = 'security';

    /** @return Written */
    public static function of(Briefing $briefing): array
    {
        $peak = $briefing->peak();

        return [
            ...$peak instanceof MemoryCap ? [self::PEAK => $peak->bytes()] : [],
            ...MatrixRecord::of($briefing->matrix()),
            ...$briefing->isSecurityOnly() ? [self::SECURITY => true] : [],
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
        $briefing = Briefing::standard()
            ->weighing($peak->isPresent() ? WrittenBytes::read($peak) : NotGiven::value())
            ->recording(MatrixRecord::read($file->field(MatrixRecord::FIELD)));

        return self::isSecurityOnly($file->field(self::SECURITY)) ? $briefing->securityOnly() : $briefing;
    }

    /**
     * Whether the file narrows the run to the security mutators: `true`
     * where it does, nothing where it does not.
     *
     * @throws NotInShape
     */
    private static function isSecurityOnly(Node $security): bool
    {
        if (! $security->isPresent()) {
            return false;
        }

        return $security->boolean() ? true : throw NotInShape::at($security->at(), 'true');
    }
}
