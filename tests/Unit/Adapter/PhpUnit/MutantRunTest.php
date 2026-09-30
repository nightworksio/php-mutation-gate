<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFile;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Ran;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project with one file, whose one mutant turns its `+` into `-`. */
function phpUnitProject(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n");

    return Project::at($root, Path::of('vendor'), Path::of('.mutation-gate'));
}

function moneyMutant(Project $project): MadeMutant
{
    $made = Engine::with(new PlusToMinus())->mutantsOf(
        Path::of('src/Money.php'),
        Contents::of((string) file_get_contents($project->absolute(Path::of('src/Money.php')))),
    );

    return $made instanceof MadeMutants ? [...$made][0] : throw new LogicException('Money has no mutant.');
}

/** A shell whose PHPUnit records these lines to the extension's results file and ends so. */
function recording(string $lines, Ran $ran): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($lines, $ran): Ran {
        file_put_contents($command->environment()[Variable::Results->value], $lines);

        return $ran;
    });
}

$adds = TestId::of('Tests\MoneySpec::addsTwoAmounts');
$request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

$judged = static function (PhpUnitShellFake $shell, TestIds $covering) use ($request): Mutant|CannotJudge {
    $project = phpUnitProject();
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'));

    return $run->judged(moneyMutant($project), $covering, $request, Seconds::of(3.0));
};

it('starts PHPUnit with the override, the extension and the covering tests\' ids, stopped at the limit', function () use ($adds, $judged): void {
    $shell = recording("passed Tests%5CMoneySpec%3A%3AaddsTwoAmounts\n", Ran::finished(succeeded: true, output: '', took: Seconds::of(0.5)));
    $judged($shell, TestIds::of($adds));
    $command = $shell->commands()[0];
    $arguments = $command->arguments();
    $ids = (string) file_get_contents(substr($arguments[6], strlen('--test-id-filter-file=')));
    [$original, $mutated] = explode(MutantFile::PAIR, $command->environment()[Variable::Mutant->value], 2);

    expect(array_slice($arguments, 0, 6))->toBe([
        PHP_BINARY,
        '-d',
        'auto_prepend_file=/gate/override.php',
        sprintf('%s/vendor/bin/phpunit', dirname($original, 2)),
        '--extension',
        Extension::class,
    ])
        ->and($arguments[6])->toStartWith('--test-id-filter-file=')
        ->and(array_slice($arguments, 7))->toBe(['--stop-on-defect', '--no-output'])
        ->and($ids)->toBe("Tests\\MoneySpec::addsTwoAmounts\n")
        ->and($original)->toEndWith('/src/Money.php')
        ->and((string) file_get_contents($mutated))->toContain('return $a - $b;')
        ->and($command->deadline())->toEqual(Seconds::of(3.0))
        ->and($command->withheld())->toEqual(Withheld::standard());
});

it('judges a mutant killed by the tests that failed or errored, and survived where every test passed', function () use ($adds, $judged): void {
    $killed = $judged(
        recording("passed Tests%5COther%3A%3Afine\nfailed Tests%5CMoneySpec%3A%3AaddsTwoAmounts\n", Ran::finished(succeeded: false, output: '', took: Seconds::of(0.5))),
        TestIds::of($adds),
    );
    $survived = $judged(recording("passed Tests%5CMoneySpec%3A%3AaddsTwoAmounts\n", Ran::finished(succeeded: true, output: '', took: Seconds::of(0.5))), TestIds::of($adds));

    expect($killed instanceof Mutant ? [$killed->status(), $killed->killers()] : [])->toEqual([MutantStatus::Killed, TestIds::of($adds)])
        ->and($killed instanceof Mutant ? $killed->duration() : null)->toEqual(Seconds::of(0.5))
        ->and($survived instanceof Mutant ? $survived->status() : null)->toBe(MutantStatus::Survived);
});

it('judges a run that fails after tests passed killed by nobody named, and one that fails before any test errored', function () use ($adds, $judged): void {
    $failed = $judged(recording("passed Tests%5CMoneySpec%3A%3AaddsTwoAmounts\n", Ran::finished(succeeded: false, output: '', took: Seconds::of(0.5))), TestIds::of($adds));
    $errored = $judged(recording('', Ran::finished(succeeded: false, output: 'Fatal error', took: Seconds::of(0.1))), TestIds::of($adds));

    expect($failed instanceof Mutant ? [$failed->status(), count($failed->killers())] : [])->toBe([MutantStatus::Killed, 0])
        ->and($errored instanceof Mutant ? $errored->status() : null)->toBe(MutantStatus::Errored);
});

it('judges a run stopped at its limit timed out, with the limit', function () use ($adds, $judged): void {
    $mutant = $judged(recording('', Ran::stopped('', Seconds::of(3.0))), TestIds::of($adds));

    expect($mutant instanceof Mutant ? [$mutant->status(), $mutant->limit()] : [])->toEqual([MutantStatus::TimedOut, Seconds::of(3.0)]);
});

it('leaves unjudged a mutant whose run passes with no test run, or whose every covering test has a line break in its name', function () use ($adds, $judged): void {
    $none = $judged(recording('', Ran::finished(succeeded: true, output: '', took: Seconds::of(0.1))), TestIds::of($adds));
    $shell = recording('', Ran::finished(succeeded: true, output: '', took: Seconds::of(0.1)));
    $unlistable = $judged($shell, TestIds::of(TestId::of("Tests\\MoneySpec::adds#with\nbreak")));

    $reasonOf = static fn(Mutant|CannotJudge $mutant): string => $mutant instanceof Mutant && $mutant->reason() instanceof Reason
        ? $mutant->reason()->text()
        : '';

    expect($none instanceof Mutant ? [$none->status(), $reasonOf($none)] : [])
        ->toBe([MutantStatus::Unjudged, 'PHPUnit ran none of the 1 tests that cover it: no id matched a test.'])
        ->and($unlistable instanceof Mutant ? [$unlistable->status(), $reasonOf($unlistable)] : [])
        ->toBe([MutantStatus::Unjudged, 'Every test that covers it has a line break in its name, which PHPUnit cannot select by id.'])
        ->and($shell->commands())->toBe([]);
});

it('lists only the covering tests it can, and keeps the run to the unit\'s group', function () use ($adds): void {
    $project = phpUnitProject();
    $shell = recording('', Ran::finished(succeeded: true, output: '', took: Seconds::of(0.1)));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'))
        ->judged(moneyMutant($project), TestIds::of($adds, TestId::of("Tests\\X::y#a\nb")), $request, Seconds::of(3.0));
    $arguments = $shell->commands()[0]->arguments();

    expect((string) file_get_contents(substr($arguments[6], strlen('--test-id-filter-file='))))->toBe("Tests\\MoneySpec::addsTwoAmounts\n")
        ->and(array_slice($arguments, -2))->toBe(['--group', 'holds:src/Money.php']);
});

it('cannot judge a mutant whose files it cannot write', function () use ($adds, $request): void {
    $project = phpUnitProject();
    Scratch::write($project->root(), '.mutation-gate/phpunit', 'a file where the directory goes');
    $shell = recording('', Ran::finished(succeeded: true, output: '', took: Seconds::of(0.1)));
    set_error_handler(static fn(): bool => true);
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'));
    $judged = $run->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));
    restore_error_handler();

    expect($judged)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot write %s/.mutation-gate/phpunit/%s/ids.txt, which the PHPUnit it starts reads.',
        $project->root(),
        moneyMutant($project)->id()->value(),
    )))
        ->and($shell->commands())->toBe([]);
});
