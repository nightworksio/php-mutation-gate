<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\StartUpTiming;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The map of a run where one test covers src/Kernel/Rates.php and src/Money.php. */
function timingMap(): CoverageMap
{
    $test = TestId::of('MoneyTest::adds');

    return CoverageMap::empty()
        ->covered(Path::of('src/Kernel/Rates.php'), Line::of(3), $test)
        ->covered(Path::of('src/Money.php'), Line::of(3), $test);
}

it('times a run of no test on a held unit\'s first file the map holds, never on the path it holds', function (): void {
    $runner = ScriptedRunner::fixture()->startingUpIn(Seconds::of(2.0), Seconds::of(0.75), Seconds::of(1.0));
    $timing = new StartUpTiming(Flows::adapters(Flows::project(), [], $runner), StartUpSamples::standard());
    $units = Units::of(Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')), Unit::file(Path::of('src/Money.php')));

    expect($timing->of(timingMap(), $units))->toEqual(Seconds::of(0.75))
        ->and(array_map(static fn(array $run): Path => $run[0], $runner->startedUp()))
        ->toEqual([Path::of('src/Kernel/Rates.php'), Path::of('src/Kernel/Rates.php'), Path::of('src/Kernel/Rates.php')]);
});

it('measures nothing where the units hold no file, or a run of no test cannot run', function (): void {
    $failing = ScriptedRunner::fixture()->startingUpIn(CannotJudge::because('no run of no test'));
    $timing = new StartUpTiming(Flows::adapters(Flows::project(), [], $failing), StartUpSamples::standard());
    $empty = ScriptedRunner::fixture();
    $none = new StartUpTiming(Flows::adapters(Flows::project(), [], $empty), StartUpSamples::standard());

    expect($timing->of(timingMap(), Units::of(Unit::file(Path::of('src/Money.php')))))->toBeInstanceOf(Unmeasured::class)
        ->and($none->of(CoverageMap::empty(), Units::of(Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')))))
        ->toBeInstanceOf(Unmeasured::class)
        ->and($empty->startedUp())->toBe([]);
});
