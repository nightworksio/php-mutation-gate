<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\PassedRecord;
use NightWorksIO\MutationGate\Core\Proof\RunProfileRecord;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Instant;

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
 * map of its own scope. `learnedMultiple` holds the `timeouts.multiple` the plan
 * learned from the ledger's kills, which every shard allows; a file
 * without it learned none (ADR-0073). `learnedPace` holds the median pace of
 * those kills, which each shard weighs a check before a mutant's tests by; a
 * file without it learned none (ADR-0077). `unchangedSince` or `reachedNothingSince` holds the
 * commit whose verdict stands for a plan that runs nothing, by why it stands
 * ({@see StandsBecause}, {@see PassedRecord}), `at` among its fields; a plan
 * without either runs what it plans.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-import-type Written from PassedRecord as PassedWritten
 *
 * @phpstan-type Written array{
 *     peak?: int,
 *     matrix?: string,
 *     security?: true,
 *     suite?: string,
 *     ownScopeCoverage?: true,
 *     early?: true,
 *     waits?: true,
 *     learnedMultiple?: float,
 *     learnedPace?: float,
 *     unchangedSince?: PassedWritten,
 *     reachedNothingSince?: PassedWritten,
 * }
 */
final readonly class BriefingRecord
{
    private const string PEAK = 'peak';

    private const string MULTIPLE = 'learnedMultiple';

    private const string PACE = 'learnedPace';

    /** Where a plan says it was made without a fresh map (ADR-0135). */
    private const string EARLY = 'early';

    /** Where a plan says it plans nothing, waiting for a caller that hands a fresh map (ADR-0135). */
    private const string WAITS = 'waits';

    /** @return Written */
    public static function of(Briefing $briefing): array
    {
        $peak = $briefing->peak();
        $unchanged = $briefing->unchanged();
        $multiple = $briefing->learned()->multiple();
        $pace = $briefing->learned()->pace();

        return [
            ...$peak instanceof MemoryCap ? [self::PEAK => $peak->bytes()] : [],
            ...RunProfileRecord::of($briefing->profile()),
            ...$briefing->isOnOwnScopeCoverage() ? [PassedRecord::OWN_SCOPE_COVERAGE => true] : [],
            ...$briefing->isEarly() ? [self::EARLY => true] : [],
            ...$briefing->waits() ? [self::WAITS => true] : [],
            ...$multiple instanceof NotGiven ? [] : [self::MULTIPLE => $multiple->times()],
            ...$pace instanceof NotGiven ? [] : [self::PACE => $pace->times()],
            ...$unchanged instanceof Unchanged
                ? [$unchanged->because()->value => PassedRecord::of($unchanged->base())]
                : [],
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

        $briefing = RunProfileRecord::isTrue($file->field(PassedRecord::OWN_SCOPE_COVERAGE))
            ? $briefing->onOwnScopeCoverage()
            : $briefing;
        $briefing = RunProfileRecord::isTrue($file->field(self::EARLY)) ? $briefing->plannedEarly() : $briefing;
        $briefing = RunProfileRecord::isTrue($file->field(self::WAITS)) ? $briefing->waitingForAMap() : $briefing;
        $multiple = $file->field(self::MULTIPLE);
        $kills = $multiple->isPresent()
            ? KillsLearned::none()->allowing(Multiple::of($multiple->number()))
            : KillsLearned::none();
        $pace = $file->field(self::PACE);
        $briefing = $briefing->learning($pace->isPresent() ? $kills->pacing(Multiple::of($pace->number())) : $kills);

        foreach (StandsBecause::cases() as $because) {
            $unchanged = $file->field($because->value);
            $briefing = $unchanged->isPresent()
                ? $briefing->unchangedSince(self::unchangedIn($because, $unchanged))
                : $briefing;
        }

        return $briefing;
    }

    /**
     * The commit whose verdict stands for this reason, as a plan file holds it, with when it passed.
     *
     * @throws NotInShape
     */
    private static function unchangedIn(StandsBecause $because, Node $unchanged): Unchanged
    {
        $passed = PassedRecord::read($unchanged);
        $at = $passed->at();

        return $at instanceof Instant
            ? Unchanged::standing($because, $passed, $at)
            : throw NotInShape::at($unchanged->at(), 'a commit that passed at an instant');
    }
}
