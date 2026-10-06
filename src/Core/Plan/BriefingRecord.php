<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\RunProfileRecord;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;

/**
 * A briefing as the plan file holds it: `peak`, the bytes the unmutated
 * suite's largest process held, where the plan measured them, `matrix`,
 * `full` where the run records every killer of each mutant, and `security`,
 * `true` where the run makes mutants with the security mutators alone,
 * `suite`, the name of the one suite whose tests alone judge the mutants, and
 * `ownScopeCoverage`, `true` where the coverage map was measured against the
 * map the run's own scope keeps. A file without `peak` measured none, one
 * without `matrix` records first killers, one without `security` makes
 * mutants with every mutator the run turns on, one without `suite` has every
 * test judge them, and one without `ownScopeCoverage` measured against no
 * map of its own scope.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{peak?: int, matrix?: string, security?: true, suite?: string, ownScopeCoverage?: true}
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
            ...RunProfileRecord::of($briefing->profile()),
            ...$briefing->isOnOwnScopeCoverage() ? [LedgerFile::OWN_SCOPE_COVERAGE => true] : [],
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
            ->ofKind(RunProfileRecord::read($file));

        return RunProfileRecord::isTrue($file->field(LedgerFile::OWN_SCOPE_COVERAGE))
            ? $briefing->onOwnScopeCoverage()
            : $briefing;
    }
}
