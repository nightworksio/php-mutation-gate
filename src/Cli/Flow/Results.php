<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * Every shard's result, read from the files the shards left. The verdict
 * requires one for every shard in the plan: a shard that crashed, was
 * cancelled or never started left none, and the verdict cannot judge. Nor
 * can it judge a shard whose runner skipped mutants it kept no record of:
 * they are unjudged, and belong to no unit.
 */
final readonly class Results
{
    private const string LEFT_NONE = <<<'SAID'
        Shard %d (%s) left no result at %s, so its mutants cannot be judged.
        A shard that crashed, was cancelled or never started leaves none. Run it again.
        SAID;

    private const string LEFT_NONE_MANY = <<<'SAID'
        Shards %s left no result in %s, so their mutants cannot be judged.
        A shard that crashed, was cancelled or never started leaves none. Run them again.
        SAID;

    /** A shard as a list of them names it: its number, and its label. */
    private const string NAMED = '%d (%s)';

    private const string ANOTHER_PLAN = <<<'SAID'
        The result of shard %d followed another plan than this one, so it cannot be merged into it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string RUNNER = 'Shard %d (%s) could not be judged: %s';

    private const string SKIPPED = <<<'SAID'
        Shard %d (%s) skipped %d mutants without a record of them, so they cannot be judged.
        The runner leaves no mutant unjudged in a run the gate judges.
        SAID;

    /** @param list<array{Shard, ShardResult, MutationResult}> $read each shard, with its result and its mutants */
    private function __construct(private array $read)
    {
    }

    /**
     * Every shard's result; or, where any cannot be judged, why each of them cannot, every shard that left none
     * named together, so one run again is enough.
     */
    public static function read(Plan $plan, Path $directory, Directory $project): self|CannotJudge
    {
        $read = [];
        $missing = [];
        $unjudged = [];

        foreach ($plan as $shard) {
            $file = Workspace::result($directory, $shard->id());
            $contents = $project->read($file);
            $result = $contents instanceof Contents ? self::resultOf($plan, $shard, $contents) : $file;

            match (true) {
                $result instanceof Path => $missing[] = [$shard, $result],
                $result instanceof CannotJudge => $unjudged[] = $result->why(),
                default => $read[] = [$shard, ...$result],
            };
        }

        $why = [...$unjudged, ...self::leftNone($missing, $directory)];

        return $why === [] ? new self($read) : CannotJudge::because(implode("\n", $why));
    }

    /**
     * Each unit a shard ran, with the mutants of it and those of them that
     * were flaky; a held unit its holding tests miss lines of did not run,
     * nor did a unit the shard's time budget ran out before.
     */
    public function units(): UnitResults
    {
        $results = UnitResults::none();

        foreach ($this->read as [$shard, $result, $mutated]) {
            foreach ($shard->units() as $unit) {
                if ($result->misses()->misses($unit->path()) || $result->unjudged()->has($unit->path())) {
                    continue;
                }

                $results = $results->with(
                    UnitResult::of($unit, Origin::Run, $this->mutantsOf($unit, $mutated->mutants()))
                        ->withFlaky($result->flaky())
                        ->judgedBy($result->covered()->testsOf($unit->path())),
                );
            }
        }

        return $results;
    }

    /** Every shard's held units whose holding tests miss lines of them. */
    public function misses(): HeldMisses
    {
        $misses = HeldMisses::none();

        foreach ($this->read as [, $result]) {
            $misses = $misses->and($result->misses());
        }

        return $misses;
    }

    /** Every shard's units its time budget ran out before (ADR-0008, decision 1). */
    public function unjudged(): Units
    {
        $unjudged = Units::none();

        foreach ($this->read as [, $result]) {
            foreach ($result->unjudged() as $unit) {
                $unjudged = $unjudged->with($unit);
            }
        }

        return $unjudged;
    }

    /**
     * Whether a shard's time budget stopped it before it judged everything:
     * it ran out before a unit, or left a mutant unjudged (ADR-0008, decision 1).
     */
    public function wereCutShort(): bool
    {
        foreach ($this->read as [, $result, $mutated]) {
            if (count($result->unjudged()) > 0 || $this->leftAny($mutated->mutants())) {
                return true;
            }
        }

        return false;
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

    /** What static analysis's checks of every shard's survivors came to, together (ADR-0020, decision 11). */
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

    /**
     * Why the shards that left no result cannot be judged: one by its file, several together by their directory.
     *
     * @param  list<array{Shard, Path}> $missing
     * @return list<string>
     */
    private static function leftNone(array $missing, Path $directory): array
    {
        if (count($missing) === 1) {
            [$shard, $file] = $missing[0];

            return [sprintf(self::LEFT_NONE, $shard->id()->number(), $shard->label(), $file->value())];
        }

        $named = array_map(
            static fn(array $left): string => sprintf(self::NAMED, $left[0]->id()->number(), $left[0]->label()),
            $missing,
        );

        return $missing === [] ? [] : [sprintf(self::LEFT_NONE_MANY, implode(', ', $named), $directory->value())];
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
        foreach ($mutants as $mutant) {
            if (OutOfTime::left($mutant)) {
                return true;
            }
        }

        return false;
    }

    /** The mutants of a unit: those of its file, or of the files inside its held path. */
    private function mutantsOf(Unit $unit, Mutants $mutants): Mutants
    {
        $of = [];

        foreach ($mutants as $mutant) {
            if ($mutant->location()->file()->within($unit->path())) {
                $of[] = $mutant;
            }
        }

        return Mutants::of(...$of);
    }
}
