<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\PassedRecord;
use NightWorksIO\MutationGate\Core\Proof\RunProfileRecord;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

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
 * file without it learned none (ADR-0077). `learnedSetup`, `learnedWindDown`
 * and `learnedPassTwo` hold what the plan learned of recent jobs on its
 * platform: the longest setup before a gate started and the longest a gate
 * went on for once it stopped, in seconds, which each job's stop is read
 * from, and the median factor of their covering tests' seconds second passes
 * took, which the second wave's shares are priced by; a file without one
 * learned none (ADR-0196). `unchangedSince`, `reachedNothingSince` or
 * `identicalTo` holds the commit whose verdict stands for a plan that runs
 * nothing, by why it stands ({@see StandsBecause}, {@see PassedRecord}), `at`
 * among its fields, and for a verdict reused, `from`, where its pass was read;
 * a plan without any runs what it plans. `inputs` holds the digest of every
 * input the run reads, where the plan read them (ADR-0198). `held` holds what the plan measured of
 * each held unit's holding tests, run alone under coverage once ({@see
 * MeasuredHoldsRecord}); a plan without it leaves each shard to measure its own.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-import-type Written from PassedRecord as PassedWritten
 * @phpstan-import-type Written from MeasuredHoldsRecord as HeldWritten
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
 *     learnedSetup?: float,
 *     learnedWindDown?: float,
 *     learnedPassTwo?: float,
 *     unchangedSince?: PassedWritten,
 *     reachedNothingSince?: PassedWritten,
 *     identicalTo?: PassedWritten,
 *     inputs?: string,
 *     held?: HeldWritten,
 * }
 */
final readonly class BriefingRecord
{
    private const string PEAK = 'peak';

    private const string MULTIPLE = 'learnedMultiple';

    private const string PACE = 'learnedPace';

    private const string SETUP = 'learnedSetup';

    private const string WIND_DOWN = 'learnedWindDown';

    private const string PASS_TWO = 'learnedPassTwo';

    /** Where a plan says it was made without a fresh map (ADR-0135). */
    private const string EARLY = 'early';

    /** Where a plan says it plans nothing, waiting for a caller that hands a fresh map (ADR-0135). */
    private const string WAITS = 'waits';

    /** Where a verdict reused says its pass was read. */
    private const string FROM = 'from';

    /** @return Written */
    public static function of(Briefing $briefing): array
    {
        $peak = $briefing->peak();
        $unchanged = $briefing->unchanged();
        $multiple = $briefing->learned()->multiple();
        $pace = $briefing->learned()->pace();
        $held = $briefing->held();

        return [
            ...$peak instanceof MemoryCap ? [self::PEAK => $peak->bytes()] : [],
            ...RunProfileRecord::of($briefing->profile()),
            ...InputsReadRecord::of($briefing->inputs()),
            ...$briefing->isEarly() ? [self::EARLY => true] : [],
            ...$briefing->waits() ? [self::WAITS => true] : [],
            ...$multiple instanceof NotGiven ? [] : [self::MULTIPLE => $multiple->times()],
            ...$pace instanceof NotGiven ? [] : [self::PACE => $pace->times()],
            ...self::jobsOf($briefing->learned()->jobs()),
            ...$unchanged instanceof Unchanged ? [$unchanged->because()->value => self::standing($unchanged)] : [],
            ...$held instanceof MeasuredHolds ? [MeasuredHoldsRecord::FIELD => MeasuredHoldsRecord::of($held)] : [],
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

        $briefing = $briefing->reading(InputsReadRecord::read($file));
        $briefing = RunProfileRecord::isTrue($file->field(self::EARLY)) ? $briefing->plannedEarly() : $briefing;
        $briefing = RunProfileRecord::isTrue($file->field(self::WAITS)) ? $briefing->waitingForAMap() : $briefing;
        $briefing = $briefing->learning(self::killsIn($file)->timing(self::jobsIn($file)));
        $held = $file->field(MeasuredHoldsRecord::FIELD);
        $briefing = $held->isPresent() ? $briefing->measuring(MeasuredHoldsRecord::read($held)) : $briefing;

        foreach (StandsBecause::cases() as $because) {
            $unchanged = $file->field($because->value);
            $briefing = $unchanged->isPresent()
                ? $briefing->unchangedSince(self::unchangedIn($because, $unchanged))
                : $briefing;
        }

        return $briefing;
    }

    /** @return array{learnedSetup?: float, learnedWindDown?: float, learnedPassTwo?: float} */
    private static function jobsOf(JobsLearned $jobs): array
    {
        $setup = $jobs->setup();
        $windDown = $jobs->windDown();
        $passTwo = $jobs->passTwo();

        return [
            ...$setup instanceof NotGiven ? [] : [self::SETUP => $setup->seconds()],
            ...$windDown instanceof NotGiven ? [] : [self::WIND_DOWN => $windDown->seconds()],
            ...$passTwo instanceof NotGiven ? [] : [self::PASS_TWO => $passTwo],
        ];
    }

    /**
     * What a briefing's file says was learned of recent jobs: their setup, their wind-down and their second pass's
     * factor.
     *
     * @throws NotInShape
     */
    private static function jobsIn(Node $file): JobsLearned
    {
        $setup = $file->field(self::SETUP);
        $windDown = $file->field(self::WIND_DOWN);
        $passTwo = $file->field(self::PASS_TWO);

        return JobsLearned::learned(
            $setup->isPresent() ? Seconds::of($setup->number()) : NotGiven::value(),
            $windDown->isPresent() ? Seconds::of($windDown->number()) : NotGiven::value(),
            $passTwo->isPresent() ? $passTwo->number() : NotGiven::value(),
        );
    }

    /** What a briefing's file says was learned of kills: the multiple a limit allows, and the pace. */
    private static function killsIn(Node $file): PlanLearned
    {
        $multiple = $file->field(self::MULTIPLE);
        $kills = $multiple->isPresent()
            ? PlanLearned::none()->allowing(Multiple::of($multiple->number()))
            : PlanLearned::none();
        $pace = $file->field(self::PACE);

        return $pace->isPresent() ? $kills->pacing(Multiple::of($pace->number())) : $kills;
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
        $from = $unchanged->field(self::FROM);

        return $at instanceof Instant
            ? Unchanged::standing($because, $passed, $at, $from->isPresent() ? $from->text() : '')
            : throw NotInShape::at($unchanged->at(), 'a commit that passed at an instant');
    }

    /** @return PassedWritten the commit whose verdict stands, and where its pass was read */
    private static function standing(Unchanged $unchanged): array
    {
        return [
            ...PassedRecord::of($unchanged->base()),
            ...$unchanged->from() === '' ? [] : [self::FROM => $unchanged->from()],
        ];
    }
}
