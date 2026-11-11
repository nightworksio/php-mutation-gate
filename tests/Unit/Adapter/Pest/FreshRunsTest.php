<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Placement;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\FreshRuns;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\JUnitLogs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding `src/Money.php`, whose second line adds one and one. */
function freshProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");

    return Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));
}

/** The mutant of `src/Money.php` that subtracts, as its own run left it: unjudged as preloaded, or as given. */
function freshRunsMutant(MutantStatus $status = MutantStatus::Unjudged): Mutant
{
    $diff = "--- Original\n+++ New\n@@ @@\n {\n-    return 1 + 1;\n+    return 1 - 1;\n }";
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
        $status,
        Unmeasured::duration(),
    );

    return $status === MutantStatus::Unjudged ? $mutant->because(Reason::preloaded(Path::of('src/Money.php'))) : $mutant;
}

/** The test of `tests/MoneySpec.php` that covers the second line of `src/Money.php`. */
function freshRunsTest(): TestId
{
    return TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds');
}

/** The result judged again through this shell, the time left to start a run in being this. */
function freshRunsOf(Project $at, ShellFake $shell, MutationResult $result, Seconds|Unlimited $left = new Unlimited()): MutationResult
{
    $map = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(2), freshRunsTest());
    $laps = Laps::since(static fn(): Seconds => Seconds::of(0.0), Seconds::of(0.0));

    $placement = Placement::inPestRunner($at->root(), Paths::none(), Path::of('vendor'), Path::of('.gate'));
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));

    return new FreshRuns($at, $placement, $shell, new CapDirectory(), Patching::on(Group::named('canary')), $bounds)
        ->judged(
            $result,
            MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
            new HandedOver($map, $at),
            static fn(): Seconds|Unlimited => $left,
            $laps,
        );
}

/** The guard file a command names, written as the gate's wrapper writes it once it served the mutated file. */
function freshServed(Command $command): void
{
    $guard = $command->environment()[Variable::Guard->value] ?? false;

    if (is_string($guard)) {
        file_put_contents($guard, sprintf("%s\n", Guard::SERVED));
    }
}

it('runs a preloaded mutant again in a fresh Pest process that prepends the override, telling only the wrapper its files', function (): void {
    $at = freshProject();
    $shell = new ShellFake(static function (Command $command): Ran {
        freshServed($command);
        JUnitLogs::failing($command->arguments(), 'it adds', 'tests/MoneySpec.php::it adds', 'Tests\MoneySpec');

        return Ran::finished(succeeded: false, output: 'FAILED');
    });

    $judged = freshRunsOf($at, $shell, MutationResult::skippingNone(Mutants::of(freshRunsMutant())));
    $command = $shell->commands()[0];
    $environment = $command->environment();
    $mutated = $environment[Variable::Mutated->value];
    $mutant = [...$judged->mutants()][0];

    expect([$mutant->status(), $mutant->killers()->has(freshRunsTest())])->toBe([MutantStatus::Killed, true])
        ->and(array_slice($command->arguments(), 1, 4))->toBe(['-d', 'opcache.enable_cli=0', '-d', sprintf('auto_prepend_file=%s/.gate/pest/override.php', $at->root())])
        ->and(is_file(sprintf('%s/.gate/pest/override.php', $at->root())))->toBeTrue()
        ->and($environment[Variable::Mutant->value])->toBe(sprintf('%s/src/Money.php', $at->root()))
        ->and(is_string($mutated) ? file_get_contents($mutated) : $mutated)->toContain('return 1 - 1;')
        ->and([$environment[Recorder::MUTANT], $environment[Recorder::MUTATED], $environment[GateVariable::Results->value]])->toBe([false, false, false])
        ->and(implode(' ', $command->arguments()))->toContain(' --filter "MoneySpec::" ')
        ->and($shell->commands())->toHaveCount(1)
        ->and([...$judged->steps()])->toEqual([StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(0.0), 1)]);
});

it('counts a fresh run that failed before any test as a kill only once the same run serving the file unmutated passes, and vouches for it', function (bool $passes, MutantStatus $status): void {
    $at = freshProject();
    $shell = new ShellFake(static function (Command $command, int $before) use ($passes): Ran {
        freshServed($command);

        return $before === 0 ? Ran::exited(255, 'PHP Fatal error: boot') : Ran::finished(succeeded: $passes, output: '');
    });

    $judged = freshRunsOf($at, $shell, MutationResult::skippingNone(Mutants::of(freshRunsMutant())));
    $mutant = [...$judged->mutants()][0];
    $control = $shell->commands()[1];
    $unmutated = $control->environment()[Variable::Mutated->value];
    $logged = sprintf('%s/.gate/pest/%s/junit.xml', $at->root(), $mutant->id()->value());

    expect($mutant->status())->toBe($status)
        ->and(count($mutant->killers()))->toBe(0)
        ->and($judged->evidence()->of($mutant->id())->isVouched())->toBe($passes)
        ->and(array_slice($control->arguments(), 1, 4))->toBe(array_slice($shell->commands()[0]->arguments(), 1, 4))
        ->and($control->arguments())->not->toContain(sprintf('--log-junit=%s', $logged))
        ->and(is_string($unmutated) ? file_get_contents($unmutated) : $unmutated)->toContain('return 1 + 1;')
        ->and($unmutated)->not->toBe($shell->commands()[0]->environment()[Variable::Mutated->value])
        ->and(array_map(static fn(StepTime $step): Step => $step->step(), [...$judged->steps()]))->toBe([Step::Mutation, Step::Controls]);
})->with([
    'passing unmutated' => [true, MutantStatus::Killed],
    'failing unmutated' => [false, MutantStatus::Unjudged],
]);

it('leaves a result with no preloaded mutant as it is, and a preloaded mutant whose run never started as it was', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $killed = MutationResult::skippingNone(Mutants::of(freshRunsMutant(MutantStatus::Killed)));
    $preloaded = MutationResult::skippingNone(Mutants::of(freshRunsMutant()));

    expect(freshRunsOf(freshProject(), $shell, $killed))->toBe($killed)
        ->and($shell->commands())->toBe([])
        ->and([...freshRunsOf(freshProject(), $shell, $preloaded, Seconds::of(0.0))->mutants()])->toEqual([freshRunsMutant()]);
});

it('survives a preloaded mutant whose fresh run served it and passed every covering test', function (): void {
    $shell = new ShellFake(static function (Command $command): Ran {
        freshServed($command);

        foreach ($command->arguments() as $argument) {
            if (str_starts_with($argument, '--log-junit=')) {
                file_put_contents(mb_substr($argument, mb_strlen('--log-junit=')), '<testsuites><testsuite name="T"><testcase name="it adds" file="tests/MoneySpec.php::it adds" class="Tests\MoneySpec"/></testsuite></testsuites>');
            }
        }

        return Ran::finished(succeeded: true, output: '');
    });

    $mutant = [...freshRunsOf(freshProject(), $shell, MutationResult::skippingNone(Mutants::of(freshRunsMutant())))->mutants()][0];

    expect($mutant->status())->toBe(MutantStatus::Survived);
});
