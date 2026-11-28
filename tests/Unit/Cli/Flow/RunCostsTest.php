<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Cores;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\RunCosts;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\VerdictTimings;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Config\Outputs;
use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RouteCost;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The results of a plan whose two shards both ran, each on this machine's cores. */
function runCostsRead(): Results
{
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    $running = new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup());
    $running->run($plan, ShardId::of(1), Workspace::results());
    $running->run($plan, ShardId::of(2), Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    return $results instanceof Results ? $results : throw new RuntimeException($results->why());
}

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each shard\'s seconds and cores, and routes each mutant a runner named no route of to its own run', function (): void {
    $results = runCostsRead();
    $began = new DateTimeImmutable('2026-09-30T13:00:00+00:00');
    $timings = VerdictTimings::of('local', $results, $began, $began, NotGiven::value());
    $report = RunCosts::of($results, $timings, Verdicts::failing(), ProcessCount::of(1), 3);
    $spent = 0.0;
    $judged = 0;

    foreach ($results->shards() as [, $result, $mutants]) {
        $spent += $result->measured()->spent()->seconds();
        $judged += count([...$mutants->mutants()]);
    }

    $routed = array_map(static fn(RouteCost $route): array => [$route->route(), $route->mutants()], [...$report->routes()]);

    expect($routed)->toBe([
        [Route::StreamFirstPass, 0],
        [Route::StreamSecondPass, 0],
        [Route::FreshRun, 0],
        [Route::OwnRun, array_sum(array_column($routed, 1))],
        [Route::StaticCheck, 0],
    ])->and(array_sum(array_column($routed, 1)))->toBeGreaterThan(0)->toBeLessThanOrEqual($judged)
        ->and($report->coreSeconds()->seconds())->toEqualWithDelta($spent * Cores::counted()->count(), 0.001)
        ->and([$report->jobs(), $report->rounds()])->toBe([3, 1]);
});

it('puts the report on the run\'s timings unless outputs.costReport is false', function (): void {
    $results = runCostsRead();
    $began = new DateTimeImmutable('2026-09-30T13:00:00+00:00');
    $timings = VerdictTimings::of('local', $results, $began, $began, NotGiven::value());

    expect(RunCosts::onto($timings, Outputs::of(), $results, Verdicts::failing())->report())
        ->toBeInstanceOf(CostReport::class)
        ->and(RunCosts::onto($timings, Outputs::of(costReport: false), $results, Verdicts::failing()))->toBe($timings);
});
