<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MemoryScan;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitScan;
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

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
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
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());

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
        ->and(array_slice($arguments, 9))->toBe([
            '--stop-on-error',
            '--stop-on-failure',
            '--no-coverage',
            '--no-logging',
            '--do-not-record-test-run-history',
            '--no-progress',
        ])
        ->and($ids)->toBe("Tests\\MoneySpec::addsTwoAmounts\n")
        ->and($told[Variable::Mutant->value])->toEndWith('/src/Money.php')
        ->and((string) file_get_contents($told[Variable::Mutated->value]))->toContain('return $a - $b;')
        ->and(dirname($told[Variable::Results->value]))->toBe(dirname($told[Variable::Guard->value]))
        ->and($command->deadline())->toEqual(Seconds::of(3.0))
        ->and($command->withheld())->toEqual(Withheld::standard());
});

it('runs every covering test of a mutant under a full kill matrix, stopping at none that fails', function () use ($adds, $request): void {
    $project = phpUnitProject();
    $shell = recording(Outcome::Passed->line($adds->value()), Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.5)));
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $run->judged(moneyMutant($project), TestIds::of($adds), $request->searching(KillSearch::of(Ordering::runner(), MatrixKind::Full)), Seconds::of(3.0));

    expect(array_slice($shell->commands()[0]->arguments(), 9))->toBe([
        '--no-coverage',
        '--no-logging',
        '--do-not-record-test-run-history',
        '--no-progress',
    ]);
});

it('judges a mutant by what its run recorded, ended as and served', function (string $lines, Ran $ran, string $guard, array $verdict) use ($adds, $judged): void {
    $selected = TestIds::of($adds, TestId::of('T::fails'), TestId::of('T::errs'), TestId::of('T::dies'));

    expect(judgedAs($judged(recording($lines, $ran, $guard), $selected)))->toBe($verdict);
})->with([
    'killed by the tests that failed or errored' => [
        records(Outcome::Started->line($adds->value()), Outcome::Passed->line($adds->value()), Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails'), Outcome::Started->line('T::errs'), Outcome::Errored->line('T::errs')),
        Ran::finished(succeeded: false, output: ''),
        "served\n",
        [MutantStatus::Killed, ['T::fails', 'T::errs'], ''],
    ],
    'killed by a test the run did not select, credited to none' => [
        records(Outcome::Started->line($adds->value()), Outcome::Passed->line($adds->value()), Outcome::Started->line('U::beside'), Outcome::Failed->line('U::beside')),
        Ran::finished(succeeded: false, output: ''),
        "served\n",
        [MutantStatus::Killed, [], ''],
    ],
    'killed by a test whose process died as it ran' => [
        records(Outcome::Started->line($adds->value()), Outcome::Passed->line($adds->value()), Outcome::Started->line('T::dies')),
        Ran::finished(succeeded: false, output: 'Fatal error'),
        "served\n",
        [MutantStatus::Killed, ['T::dies'], ''],
    ],
    'survived every test passing' => [
        records(Outcome::Started->line($adds->value()), Outcome::Passed->line($adds->value())),
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
    'killed by the tests that failed, where stopped at its limit after them, crediting none it stopped' => [
        records(Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails'), Outcome::Started->line('T::dies')),
        Ran::stopped(''),
        "served\n",
        [MutantStatus::Killed, ['T::fails'], ''],
    ],
    'errored where PHPUnit failed before any test started' => [
        '',
        Ran::finished(succeeded: false, output: 'Fatal error'),
        "served\n",
        [MutantStatus::Errored, [], ''],
    ],
    'unjudged where PHPUnit failed a run with no test failing' => [
        records(Outcome::Started->line($adds->value()), Outcome::Passed->line($adds->value())),
        Ran::finished(succeeded: false, output: 'There was 1 warning'),
        "served\n",
        [MutantStatus::Unjudged, [], "PHPUnit failed the run, though no test that ran failed. PHPUnit said:\nThere was 1 warning"],
    ],
    'unjudged where no test ran' => [
        '',
        Ran::finished(succeeded: true, output: ''),
        '',
        [MutantStatus::Unjudged, [], 'PHPUnit ran none of the 4 tests that cover it: the selection matched no test.'],
    ],
    'unjudged where every test was skipped' => [
        records(Outcome::Started->line('T::skips'), Outcome::Neither->line('T::skips')),
        Ran::finished(succeeded: true, output: ''),
        "served\n",
        [MutantStatus::Unjudged, [], 'PHPUnit skipped, or marked incomplete, every test that covers it.'],
    ],
    'unjudged where every test was set aside, even where PHPUnit fails the run' => [
        records(Outcome::Started->line('T::skips'), Outcome::Neither->line('T::skips')),
        Ran::finished(succeeded: false, output: 'failOnSkipped'),
        "served\n",
        [
            MutantStatus::Unjudged,
            [],
            "PHPUnit skipped, or marked incomplete, every test that covers it, and failed the run. PHPUnit said:\nfailOnSkipped",
        ],
    ],
    'timed out at its limit, though every test it recorded was set aside' => [
        records(Outcome::Started->line('T::skips'), Outcome::Neither->line('T::skips')),
        Ran::stopped(''),
        "served\n",
        [MutantStatus::TimedOut, [], ''],
    ],
    'unjudged, where stopped at its limit before the mutated file ran' => [
        '',
        Ran::stopped(''),
        '',
        [MutantStatus::Unjudged, [], 'PHPUnit was stopped at the limit, and the mutated file never ran in its place.'],
    ],
    'unjudged, saying PHPUnit said nothing, where it failed before any test started and said nothing' => [
        '',
        Ran::finished(succeeded: false, output: "  \n"),
        '',
        [MutantStatus::Unjudged, [], 'PHPUnit failed the run, though no test that ran failed. PHPUnit said nothing.'],
    ],
    'killed by each selected test of a class whose setUpBeforeClass failed' => [
        Outcome::ClassFailed->line(TestMethod::classOf(TestId::of('Tests\\MoneySpec::addsTwoAmounts'))),
        Ran::finished(succeeded: false, output: ''),
        "served\n",
        [MutantStatus::Killed, ['Tests\\MoneySpec::addsTwoAmounts'], ''],
    ],
    'unjudged, with what PHPUnit said, where it failed before any test started or the mutated file ran' => [
        '',
        Ran::finished(succeeded: false, output: 'No such configuration'),
        '',
        [MutantStatus::Unjudged, [], "PHPUnit failed the run, though no test that ran failed. PHPUnit said:\nNo such configuration"],
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
    $done = $judged(recording(Outcome::Passed->line($adds->value()), Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.5))), TestIds::of($adds));
    $stopped = $judged(recording('', Ran::stopped('')->took(Seconds::of(3.0))), TestIds::of($adds));

    expect($done instanceof Mutant ? $done->duration() : null)->toEqual(Seconds::of(0.5))
        ->and($stopped instanceof Mutant ? [$stopped->duration(), $stopped->limit()] : [])->toEqual([Seconds::of(3.0), Seconds::of(3.0)]);
});

it('selects the covering tests by their files where an id has a line break or ends in a carriage return', function (string $id) use ($adds): void {
    $project = phpUnitProject();
    Scratch::write($project->root(), 'tests/MoneySpec.php', "<?php\nnamespace Tests;\nfinal class MoneySpec {}\n");
    Scratch::write($project->root(), 'tests/PriceSpec.php', "<?php\nnamespace Tests;\nfinal class PriceSpec {}\n");
    $shell = recording(Outcome::Failed->line($adds->value()), Ran::finished(succeeded: false, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $mutant = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value())
        ->judged(moneyMutant($project), TestIds::of($adds, TestId::of($id)), $request, Seconds::of(3.0));
    $selection = $shell->commands()[0]->arguments()[8];

    expect($selection)->toStartWith('--test-files-file=')
        ->and((string) file_get_contents(substr($selection, strlen('--test-files-file='))))
        ->toBe(sprintf("%s/tests/MoneySpec.php\n", $project->root()))
        ->and($mutant instanceof Mutant ? $mutant->status() : null)->toBe(MutantStatus::Killed);
})->with([
    'a line break' => ["Tests\\MoneySpec::adds#with\nbreak"],
    'a carriage return at the end' => ["Tests\\MoneySpec::adds#ends\r"],
]);

it('selects the covering tests by their ids where a carriage return is inside one', function () use ($adds): void {
    $project = phpUnitProject();
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value())
        ->judged(moneyMutant($project), TestIds::of($adds, TestId::of("Tests\\MoneySpec::adds#a\rb")), $request, Seconds::of(3.0));

    expect($shell->commands()[0]->arguments()[8])->toStartWith('--test-id-filter-file=');
});

it('leaves unjudged, without a run, a mutant whose tests go by their files and one is in no file found', function () use ($adds, $judged): void {
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    $mutant = $judged($shell, TestIds::of($adds, TestId::of("Tests\\MoneySpec::adds#with\nbreak")));

    expect(judgedAs($mutant))->toBe([
        MutantStatus::Unjudged,
        [],
        'A test that covers it has a line break in its name, and no test file found holds every test that covers it.',
    ])->and($shell->commands())->toBe([]);
});

it('keeps the run to the unit\'s group', function () use ($adds): void {
    $project = phpUnitProject();
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value())
        ->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));

    expect(array_slice($shell->commands()[0]->arguments(), -2))->toBe(['--group', 'holds:src/Money.php']);
});

it('starts each run with no earlier run\'s records or guard', function () use ($adds): void {
    $project = phpUnitProject();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $run = new MutantRun($project, recording(Outcome::Failed->line('T::fails'), Ran::finished(succeeded: false, output: '')), new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $run->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));
    $again = new MutantRun($project, new PhpUnitShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: '')), new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value())
        ->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));

    expect(judgedAs($again))->toBe([MutantStatus::Unjudged, [], 'PHPUnit ran none of the 1 tests that cover it: the selection matched no test.']);
});

it('cannot judge a mutant whose files it cannot write', function () use ($adds, $request): void {
    $project = phpUnitProject();
    Scratch::write($project->root(), '.mutation-gate/phpunit', 'a file where the directory goes');
    $shell = recording('', Ran::finished(succeeded: true, output: ''));
    set_error_handler(static fn(): bool => true);
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $judged = $run->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));
    restore_error_handler();

    expect($judged)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot write %s/.mutation-gate/phpunit/%s/ids.txt, which the PHPUnit it starts reads.',
        $project->root(),
        moneyMutant($project)->id()->value(),
    )))
        ->and($shell->commands())->toBe([]);
});

/**
 * A mutant's status, its limit, and the tests that killed it, judged under a cap by a run that recorded these lines
 * and printed this, in a project whose PHPUnit config shows errors where given.
 *
 * @return list<mixed>
 */
$weighed = static function (string $lines, Ran $ran, MemoryCap $cap, ErrorDisplay|NotGiven $display) use ($adds): array {
    $project = phpUnitProject();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->cappedAt($cap);
    $run = new MutantRun($project, recording($lines, $ran), new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), $display);
    $mutant = $run->judged(moneyMutant($project), TestIds::of($adds, TestId::of('T::dies')), $request, Seconds::of(3.0));

    return $mutant instanceof Mutant
        ? [$mutant->status(), $mutant->limit(), array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()])]
        : [$mutant->why()];
};

$died = records(Outcome::Started->line('T::dies'));
$exhausted = 'PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted (tried to allocate 4096 bytes)';
$hidden = "Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message.";
$ended = 'Fatal error: Premature end of PHP process when running Tests\MoneySpec::dies.';

it('reads a mutant whose process ran out of exactly the cap as out of memory, with the cap and no killer', function (
    string $lines,
    string $output,
) use ($weighed, $exhausted): void {
    $cap = MemoryCap::of(64, MemoryUnit::Megabytes);

    expect($weighed($lines, Ran::finished(succeeded: false, output: sprintf('%s%s', $output, $exhausted)), $cap, NotGiven::value()))
        ->toEqual([MutantStatus::OutOfMemory, $cap, []]);
})->with([
    'as a test ran' => [records(Outcome::Started->line('T::dies')), ''],
    'before any test started' => ['', 'PHPUnit 13.3.4 by Sebastian Bergmann and contributors.'],
]);

it('reads a mutant as out of memory, with no limit known, where PHPUnit says its process ended with errors visibly hidden under a cap', function (
    string $output,
    ErrorDisplay|NotGiven $display,
) use ($weighed, $died): void {
    expect($weighed($died, Ran::finished(succeeded: false, output: $output), MemoryCap::of(64, MemoryUnit::Megabytes), $display))
        ->toEqual([MutantStatus::OutOfMemory, Unmeasured::duration(), []]);
})->with([
    'as PHPUnit says it hid them' => [$hidden, NotGiven::value()],
    'where the project\'s config shows them nowhere' => [$ended, ErrorDisplay::Nowhere],
]);

it('keeps the status a run gave a mutant the cap did not stop', function (
    string $lines,
    Ran $ran,
    MemoryCap $cap,
    ErrorDisplay|NotGiven $display,
    MutantStatus $status,
) use ($weighed): void {
    expect($weighed($lines, $ran, $cap, $display)[0])->toBe($status);
})->with([
    'out of a limit the project set itself' => [
        records(Outcome::Started->line('T::dies')),
        Ran::finished(succeeded: false, output: 'Allowed memory size of 50331648 bytes exhausted'),
        MemoryCap::of(64, MemoryUnit::Megabytes),
        NotGiven::value(),
        MutantStatus::Killed,
    ],
    'ended mid-test where the config shows errors on standard error, which the gate reads' => [
        records(Outcome::Started->line('T::dies')),
        Ran::finished(succeeded: false, output: 'Fatal error: Premature end of PHP process when running T::dies.'),
        MemoryCap::of(64, MemoryUnit::Megabytes),
        ErrorDisplay::Stderr,
        MutantStatus::Killed,
    ],
    'ended mid-test with errors hidden, and no cap' => [
        records(Outcome::Started->line('T::dies')),
        Ran::finished(succeeded: false, output: "Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message."),
        MemoryCap::none(),
        ErrorDisplay::Nowhere,
        MutantStatus::Killed,
    ],
    'survived, whatever it printed' => [
        records(Outcome::Started->line('T::dies'), Outcome::Passed->line('T::dies')),
        Ran::finished(succeeded: true, output: 'Allowed memory size of 67108864 bytes exhausted'),
        MemoryCap::of(64, MemoryUnit::Megabytes),
        NotGiven::value(),
        MutantStatus::Survived,
    ],
]);

it('runs a mutant\'s PHPUnit under the memory cap the run wrote, with its errors shown on the standard output', function () use ($adds, $request): void {
    $project = phpUnitProject();
    $scan = MemoryScan::in($project, MemoryCap::of(64, MemoryUnit::Megabytes), new CapDirectory());
    $seen = [];
    $shell = new PhpUnitShellFake(static function (Command $command) use (&$seen): Ran {
        $directory = $command->scanned();
        $seen[] = $directory instanceof DiskPath ? (string) file_get_contents($directory->child('memory-cap.ini')->value()) : '';

        return Ran::finished(succeeded: true, output: '');
    });
    $run = new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), $scan instanceof MemoryScan ? $scan : PhpUnitScan::uncapped($project), NotGiven::value());
    $run->judged(moneyMutant($project), TestIds::of($adds), $request, Seconds::of(3.0));

    expect($seen)->toBe(["memory_limit=64M\ndisplay_errors=stdout\n"]);
});
