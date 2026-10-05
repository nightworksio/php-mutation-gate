<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\FirstRuns;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose src/Money.php adds on line 3, and whose src/Kernel/Rates.php adds twice on line 3. */
function measuredProject(): string
{
    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b) { return \$a + \$b; }\n");
    Scratch::write($project, 'src/Kernel/Rates.php', "<?php\n\nfunction rate(\$a) { return \$a + 1 + 2; }\n");

    return $project;
}

/** The map of a run where one test of half a second covers line 3 of src/Money.php. */
function measuredMap(): CoverageMap
{
    $test = TestId::of('MoneyTest::adds');

    return CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), $test)
        ->covered(Path::of('src/Kernel/Rates.php'), Line::of(3), $test)
        ->timed($test, Seconds::of(0.5));
}

it('measures where the engine makes each unit\'s mutants, the fastest run of no test, and the processes', function (): void {
    $runner = ScriptedRunner::fixture()
        ->behaving(RunnerBehaviour::standard()->runningPerCore())
        ->startingUpIn(Seconds::of(2.5), Seconds::of(1.25), Seconds::of(3.0));
    $adapters = Flows::adapters(measuredProject(), [], $runner, Engine::with(new PlusToMinus()));
    $held = Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel'));
    $measured = new FirstRuns($adapters, StartUpSamples::standard())
        ->measured(measuredMap(), Units::of(Unit::file(Path::of('src/Money.php')), $held));

    expect($measured)->toEqual(FirstRun::of(
        measuredMap(),
        MutantSites::inFile(Path::of('src/Money.php'), Line::of(3))
            ->and(MutantSites::inFile(Path::of('src/Kernel/Rates.php'), Line::of(3), Line::of(3))),
        Seconds::of(1.25),
        ProcessCount::of(2),
    ))->and($runner->startedUp())->toEqual(array_fill(0, 3, [Path::of('src/Money.php'), $adapters->withheld]));
});

it('measures nothing where no engine counts, or no file of the units is there to count, running nothing', function (): void {
    $runner = ScriptedRunner::fixture();
    $counting = Flows::adapters(measuredProject(), [], $runner, Engine::with(new PlusToMinus()));
    $units = Units::of(Unit::file(Path::of('src/Money.php')));

    expect(new FirstRuns(Flows::adapters(measuredProject(), [], $runner), StartUpSamples::standard())->measured(measuredMap(), $units))
        ->toEqual(FirstRun::unmeasured())
        ->and(new FirstRuns($counting, StartUpSamples::standard())->measured(CoverageMap::empty(), Units::of(Unit::file(Path::of('src/Gone.php')))))
        ->toEqual(FirstRun::unmeasured())
        ->and($runner->startedUp())->toBe([]);
});

it('measures nothing where a run of no test cannot run, the first or a later one, so every unit is guessed', function (): void {
    $refused = CannotJudge::because('Pest could not start');
    $units = Units::of(Unit::file(Path::of('src/Money.php')));
    $first = ScriptedRunner::fixture()->startingUpIn($refused);
    $later = ScriptedRunner::fixture()->startingUpIn(Seconds::of(1.0), $refused, Seconds::of(0.5));
    $firstRuns = static fn(ScriptedRunner $runner): FirstRuns => new FirstRuns(
        Flows::adapters(measuredProject(), [], $runner, Engine::with(new PlusToMinus())),
        StartUpSamples::standard(),
    );

    expect($firstRuns($first)->measured(measuredMap(), $units))->toEqual(FirstRun::unmeasured())
        ->and(count($first->startedUp()))->toBe(1)
        ->and($firstRuns($later)->measured(measuredMap(), $units))->toEqual(FirstRun::unmeasured())
        ->and(count($later->startedUp()))->toBe(2);
});
