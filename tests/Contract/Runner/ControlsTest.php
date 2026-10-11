<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\RunnerContracts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Infection',
    'holds:src/Adapter/Pest',
    'holds:src/Adapter/PhpUnit',
    'holds:src/Core/Control/ControlRuns.php',
    'holds:src/Core/Control/Controls.php',
    'holds:src/Core/Coverage/OwnTime.php',
];

// What every runner finds of an unmutated control (ADR-0008, decision 2):
// Money's test that adds two amounts, run with src/Money.php served unmutated
// by the means the runner serves a mutant's file, allowed a limit, its peak
// measured by the launcher every runner starts it through (ADR-0004,
// decision 9). Each runs
// against the fake (RunnerFake) and every adapter whose library is installed,
// as the rest of the runner contract does (see RunnerTest).

afterEach(function (): void {
    Scratch::sweep();
});

/** The libraries whose tests PHPUnit runs, as the PHPUnit and Infection runners do. */
$dependents = [
    'infection' => [fn(): Library => Library::installed('infection')],
    'phpunit' => [fn(): Library => Library::installed('phpunit')],
];

/** The libraries whose runner runs a process: every one but the fake. */
$processes = [
    'pest' => [fn(): Library => Library::installed('pest'), 'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts'],
    'infection' => [fn(): Library => Library::installed('infection'), 'Tests\MoneySpec::addsTwoAmounts'],
    'phpunit' => [fn(): Library => Library::installed('phpunit'), 'Tests\MoneySpec::addsTwoAmounts'],
];

$libraries = ['the fake' => [fn(): Library => Library::fake(), 'MoneyTest::adds'], ...$processes];

/** A control of src/Money.php by one test, allowed this long. */
function moneyControl(string $test, float $limit): Control
{
    return Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of($test)), Seconds::of($limit));
}

/** What a library's runner finds of these controls, failing where it cannot run them. */
function controlsOf(Library $library, Controls $controls): ControlRuns
{
    $runs = $library->runner()->controls(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $controls);

    return $runs instanceof CannotJudge ? throw new LogicException($runs->why()) : $runs;
}

it('passes a control of a test that passes on the unmutated code, within its limit, saying how long it took', function (Library $at, string $test): void {
    $found = controlsOf($at, Controls::of(moneyControl($test, 60.0)))->of(moneyControl($test, 60.0));
    $took = $found->took();

    expect($found->end())->toBe(ControlEnd::Passed)
        ->and($took instanceof Seconds ? $took->seconds() : 0.0)->toBeGreaterThan(0.0)->toBeLessThan(60.0);
})->with($libraries)->group(...$holds);

it('serves the control\'s file through Pest\'s override, as a mutant\'s own run is, and runs its test', function (): void {
    $test = 'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts';

    expect(RunnerContracts::marks(static function () use ($test): void {
        controlsOf(Library::pest(Patching::off()), Controls::of(moneyControl($test, 60.0)));
    }))->toBe(['loaded' => true, 'wrapped' => true, 'ran' => true]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('runs a control of one named row of a data set, by the id the coverage map gives it, and that row alone', function (): void {
    $test = 'Tests\RowsSpec::marksItsNamedRow#384 bits';
    $ends = [];

    $marks = RunnerContracts::marks(static function () use ($test, &$ends): void {
        $ends[] = controlsOf(Library::infection(Seconds::of(10.0)), Controls::of(moneyControl($test, 60.0)))
            ->of(moneyControl($test, 60.0))
            ->end();
    });

    expect($ends)->toBe([ControlEnd::Passed])->and($marks['ran'])->toBeTrue();
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library')->group(...$holds);

it('runs a control of a test that depends on another with the test it depends on', function (Library $at): void {
    $test = 'Tests\\DependsSpec::marksWhenItRunsWithIt';
    $ends = [];

    $marks = RunnerContracts::marks(static function () use ($at, $test, &$ends): void {
        $ends[] = controlsOf($at, Controls::of(moneyControl($test, 60.0)))->of(moneyControl($test, 60.0))->end();
    });

    expect($ends)->toBe([ControlEnd::Passed])->and($marks['ran'])->toBeTrue();
})->with($dependents)->group(...$holds);

it('says a control ran out where its limit is too short for its tests', function (Library $at, string $test): void {

    expect(controlsOf($at, Controls::of(moneyControl($test, 0.01)))->of(moneyControl($test, 0.01))->end())->toBe(ControlEnd::RanOut);
})->with($processes)->group(...$holds);

it('measures the most memory a control\'s processes held, as every runner measures it', function (Library $at, string $test): void {
    $peak = controlsOf($at, Controls::of(moneyControl($test, 60.0)))->of(moneyControl($test, 60.0))->peak();

    expect($peak instanceof MemoryCap ? $peak->bytes() : 0)->toBeGreaterThan(MemoryCap::of(1, MemoryUnit::Megabytes)->bytes());
})->with($processes)->group(...$holds);
