<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\FailFastSavings;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanJobs;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * Every shard's result, read from the files the shards left. The verdict
 * requires one for every shard in the plan: a shard that crashed, was
 * cancelled or never started left none, and the verdict cannot judge,
 * naming how the CI said each such shard's job ended where it said so
 * (see LeftNone), unless another shard stopped once the run could not pass
 * (ADR-0008).
 * Then the run's other shards may have been cancelled, and their units
 * are stopped, as the units the doomed shard did not run are, since the
 * verdict fails whatever they come to. Nor can it judge a shard whose
 * runner skipped mutants it kept no record of: they are unjudged, and
 * belong to no unit. Where a run cuts its test files across its shards,
 * each unit's mutants are merged across the shards that hold it (see
 * MergedUnits, ADR-0117).
 *
 * @internal
 */
final readonly class Results
{
    private const string ANOTHER_SHARD = <<<'SAID'
        The result in the place of shard %d is the result of shard %d, so it cannot stand for it.
        Leave each shard's result under its own number.
        SAID;

    private const string OTHER_UNITS = <<<'SAID'
        The result of shard %d holds other units than the plan gives it, so it cannot stand for it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string ANOTHER_PLAN = <<<'SAID'
        The result of shard %d followed another plan than this one, so it cannot be merged into it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string RUNNER = 'Shard %d (%s) could not be judged: %s';

    private const string SKIPPED = <<<'SAID'
        Shard %d (%s) skipped %d mutants without a record of them, so they cannot be judged.
        The runner leaves no mutant unjudged in a run the gate judges.
        SAID;

    /**
     * @param list<array{Shard, ShardResult, MutationResult}> $read    each shard, with its result and its mutants
     * @param Stopped                                         $stopped how they stopped once the run could not pass
     * @param UnitResults                                     $units   each unit the shards ran, with its mutants
     */
    private function __construct(
        private array $read,
        private Stopped $stopped,
        private UnitResults $units,
        private HeldMisses|NotGiven $more = new NotGiven(),
    ) {
    }

    /**
     * These results, with these held units' misses too, as the verdict of a
     * run planned without a fresh map finds them against the map its
     * streams recorded (ADR-0135).
     */
    public function missing(HeldMisses $more): self
    {
        $misses = $this->more instanceof HeldMisses ? $this->more->and($more) : $more;

        return new self($this->read, $this->stopped, $this->units, $misses);
    }

    /**
     * Every shard's result; or, where any cannot be judged, why each of them cannot, every shard that left none
     * named together, so one run again is enough.
     */
    public static function read(Plan $plan, Path $directory, Directory $project): self|CannotJudge
    {
        $read = [];
        $missing = LeftNone::in($directory);
        $unjudged = [];

        foreach ($plan as $shard) {
            $result = $plan->pulls()
                ? self::pulledOf($plan, $shard, $directory, $project)
                : self::readOf($plan, $shard, Workspace::result($directory, $shard->id()), $project);

            match (true) {
                $result instanceof Path => $missing = $missing->with($shard, $result, $project),
                $result instanceof CannotJudge => $unjudged[] = $result->why(),
                default => $read[] = [$shard, ...$result],
            };
        }

        $stopped = Stopped::among($read, $missing->shards());
        $why = [...$unjudged, ...$stopped->isDoomed() ? [] : $missing->why()];
        $units = $why === [] ? MergedUnits::of($plan, $read) : CannotJudge::because(implode("\n", $why));

        return $units instanceof CannotJudge ? $units : new self($read, $stopped, $units);
    }

    /**
     * Each unit a shard ran, with the mutants of it and those of them that
     * were flaky; a held unit its holding tests miss lines of did not run,
     * nor did a unit the shard's time budget ran out before (see MergedUnits).
     */
    public function units(): UnitResults
    {
        return $this->units;
    }

    /** Every shard's held units whose holding tests miss lines of them. */
    public function misses(): HeldMisses
    {
        $misses = $this->more instanceof HeldMisses ? $this->more : HeldMisses::none();

        foreach ($this->read as [, $result]) {
            $misses = $misses->and($result->held()->misses());
        }

        return $misses;
    }

    /** Every shard's units its time budget ran out before (ADR-0008), each once, but a doomed shard's. */
    public function unjudged(): Units
    {
        $unjudged = Units::none();

        foreach ($this->read as [, $result]) {
            $unjudged = $unjudged->and($result->unjudged());
        }

        return $unjudged->except($this->stopped->units())->distinct();
    }

    /** How the shards stopped once the run could not pass, where one did (ADR-0008). */
    public function stopped(): Stopped
    {
        return $this->stopped;
    }

    /**
     * Whether a shard stopped before it judged everything: its time budget
     * ran out before a unit or left a mutant unjudged, or it stopped once the
     * run could not pass before a unit, or beside a shard that left no result
     * (ADR-0008).
     */
    public function wereCutShort(): bool
    {
        return count($this->stopped->units()) > 0 || array_any(
            $this->read,
            fn(array $one): bool => count($one[1]->unjudged()) > 0 || $this->leftAny($one[2]->mutants()),
        );
    }

    /** What every shard warns of, shard by shard. */
    public function warnings(): Warnings
    {
        $warnings = Warnings::none();

        foreach ($this->read as [, $result]) {
            foreach ($result->warnings() as $warning) {
                $warnings = $warnings->with($warning);
            }
        }

        return $warnings;
    }

    /** What stopping would have saved the shards that judged on past the survivor that doomed their run (ADR-0103). */
    public function savings(): FailFastSavings
    {
        return FailFastSavings::of(array_map(static fn(array $read): ShardResult => $read[1], $this->read));
    }

    /** What static analysis's checks of every shard's survivors came to, together (ADR-0020). */
    public function checks(): SurvivorChecks
    {
        $checks = SurvivorChecks::none();

        foreach ($this->read as [, $result]) {
            $checks = $checks->plus($result->checks());
        }

        return $checks;
    }

    /** @return list<array{Shard, ShardResult, MutationResult}> each shard, with its result and its mutants */
    public function shards(): array
    {
        return $this->read;
    }

    /** @return array{ShardResult, MutationResult}|CannotJudge|Path the result; or the file it is missing from */
    private static function readOf(Plan $plan, Shard $shard, Path $file, Directory $project): array|CannotJudge|Path
    {
        $contents = $project->read($file);

        return $contents instanceof Contents ? self::resultOf($plan, $shard, $contents) : $file;
    }

    /**
     * An item's result where the plan's shards pull their work (ADR-0134):
     * the first readable one, its own job's before the others' in their
     * order, each mutant another job's run of it judged otherwise counted
     * flaky; or why none can be judged; or the file its own job's is
     * missing from, where no job left one.
     *
     * @return array{ShardResult, MutationResult}|CannotJudge|Path
     */
    private static function pulledOf(
        Plan $plan,
        Shard $item,
        Path $directory,
        Directory $project,
    ): array|CannotJudge|Path {
        $jobs = [$item->worker()->number(), ...array_diff(array_keys(PlanJobs::of($plan)), [$item->worker()->number()])];
        $read = [];

        foreach ($jobs as $job) {
            $one = self::readOf($plan, $item, Workspace::pulled($directory, $item->id(), ShardId::of($job)), $project);
            $read = $one instanceof Path ? $read : [...$read, $one];
        }

        $judged = array_values(array_filter($read, is_array(...)));

        return match (true) {
            $judged !== [] => self::againstTheOthers($judged[0], array_slice($judged, 1)),
            $read !== [] => $read[0],
            default => Workspace::pulled($directory, $item->id(), $item->worker()),
        };
    }

    /**
     * A result, each of its mutants that another run of the item judged otherwise, or did not judge, counted flaky.
     *
     * @param  array{ShardResult, MutationResult}       $stood
     * @param  list<array{ShardResult, MutationResult}> $others
     * @return array{ShardResult, MutationResult}
     */
    private static function againstTheOthers(array $stood, array $others): array
    {
        $statuses = [];

        foreach ($stood[1]->mutants() as $mutant) {
            $statuses[$mutant->id()->value()] = $mutant->status();
        }

        $differ = [];

        foreach ($others as [, $outcome]) {
            foreach ($outcome->mutants() as $mutant) {
                $differ = ($statuses[$mutant->id()->value()] ?? null) === $mutant->status()
                    ? $differ
                    : [...$differ, $mutant->id()];
            }
        }

        return $differ === []
            ? $stood
            : [$stood[0]->withFlaky($stood[0]->flaky()->and(MutantIds::of(...$differ))), $stood[1]];
    }

    /** @return array{ShardResult, MutationResult}|CannotJudge */
    private static function resultOf(Plan $plan, Shard $shard, Contents $contents): array|CannotJudge
    {
        $result = ShardResultFile::decode($contents->text());

        $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

        return match (true) {
            $result instanceof CannotJudge => $result,
            $result->plan()->value() !== $plan->digest()->value() => CannotJudge::because(
                sprintf(self::ANOTHER_PLAN, $shard->id()->number()),
            ),
            $result->shard()->number() !== $shard->id()->number() => CannotJudge::because(
                sprintf(self::ANOTHER_SHARD, $shard->id()->number(), $result->shard()->number()),
            ),
            ! $result->units()->equals($plan->keys()->of($shard->units())) => CannotJudge::because(
                sprintf(self::OTHER_UNITS, $shard->id()->number()),
            ),
            $outcome instanceof CannotJudge => CannotJudge::because(
                sprintf(self::RUNNER, $shard->id()->number(), $shard->label(), $outcome->why()),
            ),
            $outcome->skipped() > 0 => CannotJudge::because(
                sprintf(self::SKIPPED, $shard->id()->number(), $shard->label(), $outcome->skipped()),
            ),
            default => [$result, $outcome],
        };
    }

    /** Whether a time budget left one of these mutants unjudged. */
    private function leftAny(Mutants $mutants): bool
    {
        return array_any([...$mutants], OutOfTime::left(...));
    }
}
