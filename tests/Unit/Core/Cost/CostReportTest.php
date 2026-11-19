<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostlyMutant;
use NightWorksIO\MutationGate\Core\Cost\CostlyTest;
use NightWorksIO\MutationGate\Core\Cost\CostReport;
use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RouteCost;
use NightWorksIO\MutationGate\Core\Cost\RunSpend;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Tests\Support\CostReports;

it('gives each route its mutants, wall seconds over every shard, and core-seconds by each shard\'s cores', function (): void {
    $routes = array_map(
        static function (RouteCost $route): array {
            $each = $route->perMutant();

            return [
                $route->route(),
                $route->mutants(),
                $route->seconds()->seconds(),
                $route->coreSeconds()->seconds(),
                $each instanceof Seconds ? $each->seconds() : $each,
            ];
        },
        [...CostReports::sample()->routes()],
    );

    expect($routes)->toEqual([
        [Route::StreamFirstPass, 1, 30.0, 120.0, 120.0],
        [Route::StreamSecondPass, 1, 20.0, 80.0, 80.0],
        [Route::FreshRun, 0, 15.0, 30.0, NotGiven::value()],
        [Route::OwnRun, 1, 60.0, 180.0, 180.0],
        [Route::StaticCheck, 1, 5.0, 20.0, 20.0],
    ])->and(CostReports::sample()->outside()->seconds())->toBe(20.0);
});

it('names the costliest mutants by their seconds and the costliest tests by their own seconds times the mutants on their lines', function (): void {
    $report = CostReports::sample();

    expect(array_map(
        static fn(CostlyMutant $one): array => [
            $one->mutant()->location()->start()->number(),
            $one->seconds()->seconds(),
            $one->route(),
        ],
        [...$report->mutants()],
    ))->toBe([[9, 2.0, Route::StreamSecondPass], [11, 1.0, Route::StaticCheck]])
        ->and(array_map(
            static fn(CostlyTest $one): array => [
                $one->test()->value(),
                $one->own()->seconds(),
                $one->mutants(),
                $one->atMost()->seconds(),
            ],
            [...$report->tests()],
        ))->toBe([['PriceTest::adds', 2.0, 2, 4.0], ['MoneyTest::fits', 0.25, 3, 0.75]]);
});

it('counts the run\'s jobs, rounds and unjudged mutants, and sets its core-seconds against one run of the suite', function (): void {
    $report = CostReports::sample();

    expect([$report->jobs(), $report->rounds(), $report->unjudged()])->toBe([5, 2, 1])
        ->and($report->spent()->wall()->seconds())->toBe(200.0)
        ->and($report->spent()->runner()->seconds())->toBe(400.0)
        ->and($report->coreSeconds()->seconds())->toBe(1_000.0)
        ->and($report->suite()->seconds())->toBe(2.25)
        ->and($report->multiple())->toBe(1_000.0 / 2.25)
        ->and(CostReport::FORK_FLOOR)->toBe(0.03);
});

it('takes a run with no shard to no round and one job, and a map that timed no test to no multiple', function (): void {
    $report = RunSpend::report(
        [],
        [],
        RunTimings::of('local', RunTime::measured(Seconds::of(10.0), Seconds::of(10.0))),
        JudgedMutants::none(),
        CoverageMap::of(),
        ProcessCount::of(3),
        5,
    );

    expect([$report->jobs(), $report->rounds(), $report->unjudged()])->toBe([1, 0, 0])
        ->and($report->coreSeconds()->seconds())->toBe(30.0)
        ->and($report->outside()->seconds())->toBe(0.0)
        ->and($report->multiple())->toBeInstanceOf(NotGiven::class)
        ->and([count($report->mutants()), count($report->tests())])->toBe([0, 0]);
});

it('takes one round where no second wave ran', function (): void {
    $report = RunSpend::report(
        [CostReports::shards()[0]],
        [],
        CostReports::timings(),
        JudgedMutants::none(),
        CoverageMap::of(),
        ProcessCount::of(1),
        1,
    );

    expect([$report->jobs(), $report->rounds()])->toBe([3, 1]);
});
