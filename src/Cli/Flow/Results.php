<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

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

    public static function read(Plan $plan, Path $directory, Directory $project): self|CannotJudge
    {
        $read = [];

        foreach ($plan as $shard) {
            $result = self::resultOf($plan, $shard, Workspace::result($directory, $shard->id()), $project);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            $read[] = [$shard, ...$result];
        }

        return new self($read);
    }

    /**
     * Each unit a shard ran, with the mutants of it and those of them that
     * were flaky; a held unit its holding tests miss lines of did not run.
     */
    public function units(): UnitResults
    {
        $results = UnitResults::none();

        foreach ($this->read as [$shard, $result, $mutated]) {
            foreach ($shard->units() as $unit) {
                if ($result->misses()->misses($unit->path())) {
                    continue;
                }

                $results = $results->with(
                    UnitResult::of($unit, Origin::Run, $this->mutantsOf($unit, $mutated->mutants()))
                        ->withFlaky($result->flaky()),
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

    /** @return list<array{Shard, ShardResult, MutationResult}> each shard, with its result and its mutants */
    public function shards(): array
    {
        return $this->read;
    }

    /** @return array{ShardResult, MutationResult}|CannotJudge */
    private static function resultOf(Plan $plan, Shard $shard, Path $file, Directory $project): array|CannotJudge
    {
        $contents = $project->read($file);
        $result = $contents instanceof Contents
            ? ShardResultFile::decode($contents->text())
            : CannotJudge::because(sprintf(self::LEFT_NONE, $shard->id()->number(), $shard->label(), $file->value()));

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

    /** The mutants of a unit: those of its file, or of the files inside its held path. */
    private function mutantsOf(Unit $unit, Mutants $mutants): Mutants
    {
        $of = Mutants::none();

        foreach ($mutants as $mutant) {
            $of = $mutant->location()->file()->within($unit->path()) ? $of->with($mutant) : $of;
        }

        return $of;
    }
}
