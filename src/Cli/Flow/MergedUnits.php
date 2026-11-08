<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\MergedParts;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * Each unit every shard of the plan that holds it ran, with its mutants: a
 * shard's own, or, where a run cuts its test files across its shards, each
 * merged across the parts those shards judged it by (ADR-0117), with the
 * mutants any of them found flaky. A unit a shard that holds it left no
 * result for, its time budget ran out before, or whose holding tests miss
 * lines of it, did not run.
 *
 * @internal
 */
final readonly class MergedUnits
{
    /**
     * The units the plan's shards ran, in the order the shards take them;
     * or why one's parts cannot be merged.
     *
     * @param list<array{Shard, ShardResult, MutationResult}> $read each shard that left a result, with it
     */
    public static function of(Plan $plan, array $read): UnitResults|CannotJudge
    {
        $ran = self::ran($read);
        $results = UnitResults::none();

        foreach (self::holders($plan) as [$unit, $holders]) {
            $path = $unit->path()->value();
            $parts = array_key_exists($path, $ran) ? $ran[$path] : [];
            $merged = $parts !== [] && count($parts) === $holders ? self::merged($unit, $parts) : UnitResults::none();

            if ($merged instanceof CannotJudge) {
                return $merged;
            }

            $results = $results->and($merged);
        }

        return $results;
    }

    /**
     * Each unit of the plan's shards, in the order they take them, with how
     * many shards hold it.
     *
     * @return array<string, array{Unit, int}> by its path
     */
    private static function holders(Plan $plan): array
    {
        $holders = [];

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $path = $unit->path()->value();
                $held = array_key_exists($path, $holders) ? $holders[$path] : [$unit, 0];
                $holders[$path] = [$held[0], $held[1] + 1];
            }
        }

        return $holders;
    }

    /**
     * Each shard's result and mutants, by the path of each unit it ran.
     *
     * @param  list<array{Shard, ShardResult, MutationResult}>            $read
     * @return array<string, list<array{ShardResult, MutationResult}>>
     */
    private static function ran(array $read): array
    {
        $ran = [];

        foreach ($read as [$shard, $result, $mutated]) {
            foreach ($shard->units() as $unit) {
                if (! $result->held()->misses()->misses($unit->path()) && ! $result->unjudged()->has($unit->path())) {
                    $ran[$unit->path()->value()][] = [$result, $mutated];
                }
            }
        }

        return $ran;
    }

    /**
     * A unit, its mutants merged across the shards that ran it.
     *
     * @param non-empty-list<array{ShardResult, MutationResult}> $parts
     */
    private static function merged(Unit $unit, array $parts): UnitResults|CannotJudge
    {
        $mutants = [];

        foreach ($parts as [, $mutated]) {
            $mutants[] = self::mutantsOf($unit, $mutated->mutants());
        }

        $merged = MergedParts::of($unit->path(), ...$mutants);
        $flaky = MutantIds::none();

        foreach ($parts as [$result]) {
            $flaky = $flaky->and($result->flaky());
        }

        return $merged instanceof CannotJudge ? $merged : UnitResults::of(
            UnitResult::of($unit, Origin::Run, $merged)
                ->withFlaky($flaky)
                ->judgedBy($parts[0][0]->held()->covered()->testsOf($unit->path())),
        );
    }

    /** The mutants of a unit: those of its file, or of the files inside its held path. */
    private static function mutantsOf(Unit $unit, Mutants $mutants): Mutants
    {
        return Mutants::of(...array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->location()->file()->within($unit->path()),
        ));
    }
}
