<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\Config\Outputs;
use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RoutedMutant;
use NightWorksIO\MutationGate\Core\Cost\RunSpend;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardSpend;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * Where a run's minutes went, read from its shards' results (ADR-0230):
 * each shard's seconds, steps and cores, the jobs of a second wave that
 * judged its shares, and each mutant it judged with the route its runner
 * named. A shard whose gate did not count its cores is taken to have the
 * verdict's.
 *
 * @internal
 */
final readonly class RunCosts
{
    /** These timings, with where the run's minutes went, where `outputs.costReport` asks for it. */
    public static function onto(RunTimings $timings, Outputs $outputs, Results $results, Verdict $verdict): RunTimings
    {
        return $outputs->reportsCosts()
            ? $timings->withReport(self::of($results, $timings, $verdict, Cores::counted(), $outputs->costliest()))
            : $timings;
    }

    public static function of(
        Results $results,
        RunTimings $timings,
        Verdict $verdict,
        ProcessCount $cores,
        int $costliest,
    ): CostReport {
        $shards = [];
        $judged = [];

        foreach ($results->shards() as [, $result, $mutants]) {
            $measured = $result->measured();
            $counted = $measured->cores();
            $shards[] = ShardSpend::of(
                $measured->spent(),
                $measured->steps(),
                $counted instanceof ProcessCount ? $counted : $cores,
                count($measured->shares()),
            );
            $evidence = $mutants->evidence();

            foreach ($mutants->mutants() as $mutant) {
                $given = $evidence->of($mutant->id());
                $judged[] = RoutedMutant::of(
                    $mutant,
                    Route::of($mutant->status(), $given->route(), $given->isStreamed()),
                );
            }
        }

        return RunSpend::report(
            $shards,
            $judged,
            $timings,
            $verdict->trees()->mutants(),
            $verdict->matrix()->coverage(),
            $cores,
            $costliest,
        );
    }
}
