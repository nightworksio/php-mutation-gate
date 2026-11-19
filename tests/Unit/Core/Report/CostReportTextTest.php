<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\RoutedMutant;
use NightWorksIO\MutationGate\Core\Cost\RunSpend;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\CostReportText;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Tests\Support\CostReports;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('says where the run\'s minutes went, collapsed: its time, each route beside the fork floor, and its costliest mutants and tests', function (): void {
    $report = CostReports::sample();
    $id = static fn(int $at): string => Escape::code([...$report->mutants()][$at]->mutant()->id()->value());

    expect(CostReportText::markdown($report))->toBe(implode("\n\n", [
        "<details><summary>Where this run's minutes went</summary>",
        "3m wall, 7m runner time, 5 jobs in 2 rounds, 1 unjudged.\n"
        . 'Core time 17m: 444.4 test suite runs of 2s each. A full run should cost 2 to 3.',
        implode("\n", [
            '| Route | Mutants | Wall | Core-seconds a mutant |',
            '|---|---|---|---|',
            '| stream first pass | 1 | 30s | 120.00s |',
            '| stream second pass | 1 | 20s | 80.00s |',
            '| fresh run | 0 | 15s |  |',
            '| own run | 1 | 1m | 180.00s |',
            '| static check | 1 | 5s | 20.00s |',
        ]),
        "A fork alone takes 0.03s of a core: no route can judge a mutant for less.\n"
        . "20s of the shards' time went to no route: start-up, coverage, trials, controls.",
        implode("\n", [
            '| Costliest mutant | Where | Mutator | Route | Seconds |',
            '|---|---|---|---|---|',
            sprintf('| %s | %s | %s | stream second pass | 2.00s |', $id(0), Escape::code('src/Money.php:9'), Escape::code('LessThan')),
            sprintf('| %s | %s | %s | static check | 1.00s |', $id(1), Escape::code('src/Money.php:11'), Escape::code('LessThan')),
        ]),
        implode("\n", [
            '| Costliest test | Own time | Mutants on its lines | At most |',
            '|---|---|---|---|',
            sprintf('| %s | 2.00s | 2 | 4s |', Escape::code('PriceTest::adds')),
            sprintf('| %s | 0.25s | 3 | 1s |', Escape::code('MoneyTest::fits')),
        ]),
        '</details>',
    ]));
});

it('says one job and no round of a run with no shard, leaves out empty tables and an unrouted mutant\'s route, and names no suite it cannot set against', function (): void {
    $report = RunSpend::report(
        [],
        [RoutedMutant::of(CostReports::mutant(3, MutantStatus::Killed, Seconds::of(1.5)), NotGiven::value())],
        RunTimings::of('local', RunTime::measured(Seconds::of(10.0), Seconds::of(10.0))),
        JudgedMutants::none(),
        CoverageMap::of(),
        ProcessCount::of(1),
        3,
    );
    $text = CostReportText::markdown($report);

    expect($text)->toContain("10s wall, 10s runner time, 1 job in 0 rounds, 0 unjudged.\n"
        . 'Core time 10s. The coverage map timed no test to set it against.')
        ->and($text)->toContain('|  | 1.50s |')
        ->and($text)->not->toContain('Costliest test');
});

it('keeps a test\'s hostile name as text in its cell', function (): void {
    $hostile = TestId::of('<script>x</script>|[a](b)');
    $text = CostReportText::markdown(RunSpend::report(
        [],
        [RoutedMutant::of(CostReports::mutant(3, MutantStatus::Killed, Seconds::of(1.0)), Route::OwnRun)],
        CostReports::timings(),
        JudgedMutants::none(),
        CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(3), $hostile)->timed($hostile, Seconds::of(1.0)),
        ProcessCount::of(1),
        3,
    ));

    expect($text)->not->toContain('<script>')
        ->and($text)->not->toContain('|[a]')
        ->and($text)->toContain(sprintf('| %s | 1.00s | 1 | 1s |', Escape::code('<script>x</script>|[a](b)')));
});

it('says nothing of a verdict whose run gave no report', function (): void {
    $verdict = Verdicts::failing();

    expect(CostReportText::of($verdict))->toBe('')
        ->and(CostReportText::of($verdict->withAccount($verdict->account()->withTimings(
            CostReports::timings()->withReport(CostReports::sample()),
        ))))->toBe(CostReportText::markdown(CostReports::sample()));
});
