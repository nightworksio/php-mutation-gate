<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
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

/** A shell whose PHPUnit records these lines to the extension's results file, these to the guard file, and ends so. */
function recording(string $lines, Ran $ran, string $guard = "served\n"): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($lines, $ran, $guard): Ran {
        file_put_contents($command->environment()[Variable::Results->value], $lines);
        file_put_contents($command->environment()[Variable::Guard->value], $guard);

        return $ran;
    });
}

/** The lines of a results file, one after another. */
function records(string ...$lines): string
{
    return implode('', $lines);
}

/**
 * A mutant's status, the tests that killed it, and why it is unjudged.
 *
 * @return list<mixed>
 */
function judgedAs(Mutant|CannotJudge $mutant): array
{
    return $mutant instanceof Mutant
        ? [
            $mutant->status(),
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
            $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
        ]
        : [$mutant->why()];
}

$adds = TestId::of('Tests\MoneySpec::addsTwoAmounts');
$request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

$judged = static function (PhpUnitShellFake $shell, TestIds $covering) use ($request): Mutant|CannotJudge {
    $project = phpUnitProject();
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'));

    return $run->judged(moneyMutant($project), $covering, $request, Seconds::of(3.0));
};

it('starts PHPUnit with opcache off, the override, the extension and the covering tests\' ids, stopped at the limit', function () use ($adds, $judged): void {
    $shell = recording(Outcome::Passed->line($adds->value()), Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.5)));
    $judged($shell, TestIds::of($adds));
    $command = $shell->commands()[0];
    $arguments = $command->arguments();
    $ids = (string) file_get_contents(substr($arguments[8], strlen('--test-id-filter-file=')));
    $told = $command->environment();

    expect(array_slice($arguments, 0, 8))->toBe([
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'auto_prepend_file=/gate/override.php',
        sprintf('%s/vendor/bin/phpunit', dirname($told[Variable::Mutant->value], 2)),
        '--extension',
        Extension::class,
    ])
        ->and($arguments[8])->toStartWith('--test-id-filter-file=')
        ->and(array_slice($arguments, 9))->toBe(['--stop-on-error', '--stop-on-failure', '--no-progress'])
        ->and($ids)->toBe("Tests\\MoneySpec::addsTwoAmounts\n")
        ->and($told[Variable::Mutant->value])->toEndWith('/src/Money.php')
        ->and((string) file_get_contents($told[Variable::Mutated->value]))->toContain('return $a - $b;')
        ->and(dirname($told[Variable::Results->value]))->toBe(dirname($told[Variable::Guard->value]))
        ->and($command->deadline())->toEqual(Seconds::of(3.0))
        ->and($command->withheld())->toEqual(Withheld::standard());
});

it('judges a mutant by what its run recorded, ended as and served', function (string $lines, Ran $ran, string $guard, array $verdict) use ($adds, $judged): void {
    expect(judgedAs($judged(recording($lines, $ran, $guard), TestIds::of($adds))))->toBe($verdict);
})->with([
    'killed by the tests that failed or errored' => [
        records(Outcome::Started->line('T::fine'), Outcome::Passed->line('T::fine'), Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails'), Outcome::Started->line('T::errs'), Outcome::Errored->line('T::errs')),
        Ran::finished(succeeded: false, output: ''),
        "served\n",
        [MutantStatus::Killed, ['T::fails', 'T::errs'], ''],
    ],
    'killed by a test whose process died as it ran' => [
        records(Outcome::Started->line('T::fine'), Outcome::Passed->line('T::fine'), Outcome::Started->line('T::dies')),
        Ran::finished(succeeded: false, output: 'Fatal error'),
        "served\n",
        [MutantStatus::Killed, ['T::dies'], ''],
    ],
    'survived every test passing' => [
        records(Outcome::Started->line('T::fine'), Outcome::Passed->line('T::fine')),
        Ran::finished(succeeded: true, output: ''),
        "served\n",
        [MutantStatus::Survived, [], ''],
    ],
    'timed out at its limit' => [
        Outcome::Started->line('T::loops'),
        Ran::stopped(''),
        "served\n",
        [MutantStatus::TimedOut, [], ''],
    ],
    'errored where PHPUnit failed before any test started' => [
        '',
        Ran::finished(succeeded: false, output: 'Fatal error'),
        "served\n",
        [MutantStatus::Errored, [], ''],
    ],
    'unjudged where PHPUnit failed a run with no test failing' => [
        records(Outcome::Started->line('T::warns'), Outcome::Passed->line('T::warns')),
        Ran::finished(succeeded: false, output: 'There was 1 warning'),
        "served\n",
        [MutantStatus::Unjudged, [], "PHPUnit failed the run, though no test that ran failed. PHPUnit said:\nThere was 1 warning"],
    ],
    'unjudged where no test ran' => [
        '',
        Ran::finished(succeeded: true, output: ''),
        '',
        [MutantStatus::Unjudged, [], 'PHPUnit ran none of the 1 tests that cover it: no id matched a test.'],
    ],
    'unjudged where every test was skipped' => [
        records(Outcome::Started->line('T::skips'), Outcome::Neither->line('T::skips')),
        Ran::finished(succeeded: true, output: ''),
        "served\n",
        [MutantStatus::Unjudged, [], 'PHPUnit skipped every test that covers it.'],
    ],
    'unjudged where the mutated file never ran, whatever the tests did' => [
        records(Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails')),
        Ran::finished(succeeded: false, output: ''),
        '',
        [
            MutantStatus::Unjudged,
            [],
            'The mutated file never ran in its place: PHPUnit loaded the file some other way, such as another wrapper.',
        ],
    ],
    'unjudged where opcache could have run a cached original' => [
        records(Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails')),
        Ran::finished(succeeded: false, output: ''),
        "cached\nserved\n",
        [MutantStatus::Unjudged, [], 'Opcache ran on the command line, so a cached original could have run in its place.'],
    ],
]);

it('says how long the run took, and the limit of one that timed out', function () use ($adds, $judged): void {
    $done = $judged(recording(Outcome::Passed->line('T::fine'), Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.5))), TestIds::of($adds));
    $stopped = $judged(recording('', Ran::stopped('')->took(Seconds::of(3.0))), TestIds::of($adds));

    expect($done instanceof Mutant ? $done->duration() : null)->toEqual(Seconds::of(0.5))
        ->and($stopped instanceof Mutant ? [$stopped->duration(), $stopped->limit()] : [])->toEqual([Seconds::of(3.0), Seconds::of(3.0)]);
});

it('leaves unjudged, without a run, a mutant whose every covering test has a line break in its name', function () use ($judged): void {
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    $unlistable = $judged($shell, TestIds::of(TestId::of("Tests\\MoneySpec::adds#with\nbreak")));

    expect(judgedAs($unlistable))
        ->toBe([MutantStatus::Unjudged, [], 'Every test that covers it has a line break in its name, which PHPUnit cannot select by id.'])
        ->and($shell->commands())->toBe([]);
});

it('lists only the covering tests it can, and keeps the run to the unit\'s group', function () use ($adds): void {
    $project = phpUnitProject();
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'))
        ->judged(moneyMutant($project), TestIds::of($adds, TestId::of("Tests\\X::y#a\nb")), $request, Seconds::of(3.0));
    $arguments = $shell->commands()[0]->arguments();

    expect((string) file_get_contents(substr($arguments[8], strlen('--test-id-filter-file='))))->toBe("Tests\\MoneySpec::addsTwoAmounts\n")
        ->and(array_slice($arguments, -2))->toBe(['--group', 'holds:src/Money.php']);
});

it('starts each run with no earlier run\'s records or guard', function () use ($adds): void {
    $project = phpUnitProject();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $run = new MutantRun($project, recording(Outcome::Failed->line('T::fails'), Ran::finished(succeeded: false, output: '')), new Invocation($project, '/gate/override.php'));
    $run->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));
    $again = new MutantRun($project, new PhpUnitShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: '')), new Invocation($project, '/gate/override.php'))
        ->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));

    expect(judgedAs($again))->toBe([MutantStatus::Unjudged, [], 'PHPUnit ran none of the 1 tests that cover it: no id matched a test.']);
});

it('cannot judge a mutant whose files it cannot write', function () use ($adds, $request): void {
    $project = phpUnitProject();
    Scratch::write($project->root(), '.mutation-gate/phpunit', 'a file where the directory goes');
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
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
