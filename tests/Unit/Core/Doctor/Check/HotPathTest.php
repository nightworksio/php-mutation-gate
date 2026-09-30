<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Doctor\Check\HotPath;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/**
 * A suite of 20 tests, each taking a quarter of a second: all of them run
 * src/Kernel.php and src/Boot.php, and one runs src/Money.php.
 */
$suite = static function (): CoverageMap {
    $map = CoverageMap::empty();

    foreach (range(1, 20) as $number) {
        $test = TestId::of(sprintf('Tests\\KernelTest::test%d', $number));
        $map = $map->covered(Path::of('src/Kernel.php'), Line::of(9), $test)
            ->covered(Path::of('src/Boot.php'), Line::of(4), $test)
            ->timed($test, Seconds::of(0.25));
    }

    return $map->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\\KernelTest::test1'));
};

$measured = static fn(CoverageMap|CannotJudge $coverage, Units $held, Timings $timings): Observations
    => Observations::none()
        ->withSettings(Configs::settings(['runner' => 'pest']))
        ->withMeasurement(Measurement::of($coverage, $held, $timings));

it('names each hot path nothing holds, with what one of its mutants costs', function () use ($measured, $suite): void {
    $boot = Finding::of(
        Slug::HotPathUnheld,
        Severity::Slow,
        '`src/Boot.php` is run by 20 of 20 tests, and nothing holds it.',
        'Each of its mutants runs most of the suite, which costs time and never a verdict.',
        'Hold it with the tests that assert what it does: #[Holds(\'src/Boot.php\')] on them.',
    )->costing(Seconds::of(5.0));

    expect(HotPath::in($measured($suite(), Units::of(Unit::held(Path::of('src/Kernel.php'), Group::holding('src/Kernel.php'))), Timings::none())))
        ->toEqual(Findings::of($boot));
});

it('costs a hot path at what the ledgers learned it takes, where they learned it', function () use ($measured, $suite): void {
    $learned = Timings::of(Timing::of(
        Path::of('src/Kernel.php'),
        Seconds::of(42.5),
        'pest',
        Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')),
    ));
    $found = [...HotPath::in($measured($suite(), Units::none(), $learned))];

    expect(array_map(static fn(Finding $finding): string => $finding->found(), $found))->toBe([
        '`src/Kernel.php` is run by 20 of 20 tests, and nothing holds it.',
        '`src/Boot.php` is run by 20 of 20 tests, and nothing holds it.',
    ])->and(array_map(static fn(Finding $finding): mixed => $finding->atStake(), $found))
        ->toEqual([Seconds::of(42.5), Seconds::of(5.0)]);
});

it('finds no hot path in a run that gave no map, or where nothing was measured', function () use ($measured): void {
    expect(HotPath::in($measured(CannotJudge::because('No suite.'), Units::none(), Timings::none())))->toEqual(Findings::none())
        ->and(HotPath::in(Observations::none()))->toEqual(Findings::none());
});

it('finds no hot path without settings to say what share is hot', function () use ($suite): void {
    expect(HotPath::in(Observations::none()->withMeasurement(Measurement::of($suite(), Units::none(), Timings::none()))))
        ->toEqual(Findings::none());
});
