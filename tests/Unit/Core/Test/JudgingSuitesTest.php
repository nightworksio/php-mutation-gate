<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

$unit = static fn(): Suites => Suites::named(SuiteName::of('Unit'));
$process = static fn(): Suites => Suites::named(SuiteName::of('Process'));

it('runs every suite for any tests where neither list names one', function (): void {
    $every = JudgingSuites::every();

    expect($every->for(WholeSuite::tests()))->toEqual(Suites::all())
        ->and($every->for(Group::named('holds:src/Shell.php')))->toEqual(Suites::all())
        ->and($every->everyUnit())->toEqual(Suites::all())
        ->and($every->heldUnits())->toEqual(NotGiven::value())
        ->and($every->namesAny())->toBeFalse();
});

it('runs the judging suites alone where none holds, whatever the tests', function () use ($unit): void {
    $judging = JudgingSuites::judging($unit());

    expect($judging->for(WholeSuite::tests()))->toEqual($unit())
        ->and($judging->for(Group::named('holds:src/Shell.php')))->toEqual($unit())
        ->and($judging->heldUnits())->toEqual(NotGiven::value())
        ->and($judging->namesAny())->toBeTrue();
});

it('runs the holding suites too for the tests that hold a unit, and never for the whole suite or some test files', function () use ($unit, $process): void {
    $holding = JudgingSuites::holding($unit(), $process());
    $both = Suites::named(SuiteName::of('Unit'), SuiteName::of('Process'));

    expect($holding->for(WholeSuite::tests()))->toEqual($unit())
        ->and($holding->for(TestPaths::of(Paths::of(Path::of('tests/Unit/ShellTest.php')))))->toEqual($unit())
        ->and($holding->for(Group::named('holds:src/Shell.php')))->toEqual($both)
        ->and($holding->for(Filter::matching('ShellTest')))->toEqual($both)
        ->and($holding->everyUnit())->toEqual($unit())
        ->and($holding->heldUnits())->toEqual($process())
        ->and(JudgingSuites::holding(Suites::all(), $process())->namesAny())->toBeTrue();
});
