<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Doctor\Check\CoverageRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
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

it('finds that measuring the suite failed, with why', function () use ($measured): void {
    expect(CoverageRun::in($measured(CannotJudge::because('Pest\'s coverage run failed. Pest said: 1 failed.'), Units::none(), Timings::none())))
        ->toEqual(Findings::of(Finding::of(
            Slug::CoverageRunFailed,
            Severity::WillFail,
            'Measuring the suite failed. Pest\'s coverage run failed. Pest said: 1 failed.',
            'A run begins the same way, finding the units and running the suite under coverage, or cannot judge.',
            'Run vendor/bin/mutation-gate coverage to see the same failure, and fix the test or the driver it names.',
        )));
});

it('finds a coverage run that covered nothing', function () use ($measured): void {
    expect(CoverageRun::in($measured(CoverageMap::empty(), Units::none(), Timings::none())))->toEqual(Findings::of(Finding::of(
        Slug::CoverageEmpty,
        Severity::WillFail,
        'The suite\'s coverage run passed, and covered no line of any file.',
        'A mutant no test covers is never killed, so no run could judge one.',
        "Collect coverage where the gate runs, with XDEBUG_MODE=coverage or pcov.enabled=1,\nand list the trees in the <source> of phpunit.xml.",
    )));
});

it('finds nothing of the run where it covered something, or nothing was measured', function () use ($measured, $suite): void {
    expect(CoverageRun::in($measured($suite(), Units::none(), Timings::none())))->toEqual(Findings::none())
        ->and(CoverageRun::in(Observations::none()))->toEqual(Findings::none());
});
