<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;
use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\FailFastSavings;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

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
            $result = LeftResults::of($plan, $shard, $directory, $project);

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

    /** Whether a time budget left one of these mutants unjudged. */
    private function leftAny(Mutants $mutants): bool
    {
        return array_any([...$mutants], OutOfTime::left(...));
    }
}
