<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Trial;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\TrialRun;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project in a new directory, holding src/Money.php and src/Tax.php, where each trial's guard would be written in
 * the directory of its position in the batch.
 */
function trialProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfinal class Tax\n{\n}\n");

    return Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** The copy of a file of the project a trial's run of its tests on their own serves unmutated, in its place. */
function trialUnmutated(Project $at, string $file): string
{
    $printed = Printed::of(Contents::of((string) file_get_contents(sprintf('%s/%s', $at->root(), $file))), Path::of($file));

    return sprintf('%s/originals/%s.php', $at->root(), hash('xxh3', $printed instanceof Contents ? $printed->text() : ''));
}

/** A run of some tests on their own, the file they judge served unmutated through Pest's override. */
function trialServed(Project $at, Command $judging, string $file = 'src/Money.php'): Command
{
    return $judging->with([
        'PEST_MUTATION_TESTING' => sprintf('%s/%s', $at->root(), $file),
        'PEST_MUTATION_FILE' => trialUnmutated($at, $file),
    ]);
}

/** Answers the tests on their own as passing, and a mutant's run by writing this guard and ending so. */
function trialAnswering(string $guard, Ran $mutant): Closure
{
    return static function (Command $command) use ($guard, $mutant): Ran {
        $environment = $command->environment();
        $file = array_key_exists('MUTATION_GATE_GUARD', $environment) ? $environment['MUTATION_GATE_GUARD'] : false;

        if (is_string($file) && $guard !== '') {
            file_put_contents($file, $guard);
        }

        return is_string($file) ? $mutant : Ran::finished(succeeded: true, output: '');
    };
}

/** The run of some tests a trial starts, writing its JUnit log beside a guard in this directory. */
function trialJudging(string $directory, Paths $tests, WholeSuite|Group $judgedBy = new WholeSuite()): Command
{
    return Invocation::installedIn(Path::of('vendor'))
        ->judging($tests, $judgedBy, Withheld::standard(), sprintf('--log-junit=%s/junit.xml', $directory));
}

/** A trial of the whole suite. */
function trialOf(Project $at, ShellFake $shell, WholeSuite|Group $judgedBy = new WholeSuite(), MatrixKind $matrix = MatrixKind::FirstKiller): Trial
{
    return new Trial(
        $at,
        $shell,
        Invocation::installedIn(Path::of('vendor')),
        $judgedBy,
        Withheld::standard(),
        $at->root(),
        uncappedScan($at),
        WorkerSlots::alone(),
        $matrix,
    );
}

it('runs the tests with Pest\'s override serving the mutated copy, and a guard, within the limit it is given', function (): void {
    $at = trialProject();
    $shell = new ShellFake(trialAnswering('{"before":false,"loaded":true,"opcache":false}', Ran::finished(succeeded: false, output: '')));
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));
    $judging = trialJudging(sprintf('%s/0', $at->root()), $tests);

    $outcome = trialOf($at, $shell)->of($tests, Path::of('src/Money.php'), '/copies/n1.php', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::Killed)
        ->and($outcome->duration())->toBeInstanceOf(Seconds::class)
        ->and($shell->commands())->toEqual([
            trialServed($at, $judging->within(Seconds::of(6.0))),
            $judging->within(Seconds::of(6.0))->with([
                'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
                'PEST_MUTATION_FILE' => '/copies/n1.php',
                'MUTATION_GATE_GUARD' => sprintf('%s/0/guard.json', $at->root()),
            ]),
        ]);
});

it('leaves a mutant the tests pass with alive, and one stopped at its limit timed out', function (): void {
    $at = trialProject();
    $guard = '{"before":false,"loaded":true,"opcache":false}';
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));

    expect(trialOf($at, new ShellFake(trialAnswering($guard, Ran::finished(succeeded: true, output: ''))))
        ->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0))->status())->toBe(MutantStatus::Survived)
        ->and(trialOf($at, new ShellFake(trialAnswering('', Ran::stopped(''))))
            ->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0))->status())->toBe(MutantStatus::TimedOut);
});

it('judges nothing where the guard says the original ran, or cannot say', function (string $guard, string $reason): void {
    $at = trialProject();
    mkdir(sprintf('%s/0', $at->root()));
    file_put_contents(sprintf('%s/0/guard.json', $at->root()), '{"before":false,"loaded":true,"opcache":false}');
    $shell = new ShellFake(trialAnswering($guard, Ran::exited(0, '')));

    $outcome = trialOf($at, $shell)->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::Unjudged)
        ->and($outcome->reason())->toEqual(Reason::that(sprintf('%s (exit code 0; ran tests/A.php)', $reason)))
        ->and($outcome->duration())->toBeInstanceOf(Seconds::class);
})->with([
    'loaded before the override' => ['{"before":true,"loaded":true,"opcache":false}', 'loaded before the override'],
    'opcache on' => ['{"before":false,"loaded":true,"opcache":true}', 'opcache.enable_cli or opcache.file_cache on'],
    'never loaded' => ['{"before":false,"loaded":false,"opcache":false}', 'never loaded'],
    'no guard, and none left from before' => ['', 'the run wrote no guard, so the gate cannot tell the mutated file ran'],
    'a guard of another shape' => ['{"before":1}', 'the run wrote no guard, so the gate cannot tell the mutated file ran'],
]);

it('kills a mutant whose run a signal ended, writing no guard, where its tests pass on their own', function (
    int $code,
    MutantStatus $status,
): void {
    $outcome = trialOf(trialProject(), new ShellFake(trialAnswering('', Ran::exited($code, ''))))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->status())->toBe($status);
})->with([
    'the first signal' => [129, MutantStatus::Killed],
    'a segmentation fault' => [139, MutantStatus::Killed],
    'the last signal' => [192, MutantStatus::Killed],
    'no signal' => [128, MutantStatus::Unjudged],
    'past the last signal' => [193, MutantStatus::Unjudged],
    'PHP\'s fatal error' => [255, MutantStatus::Unjudged],
]);

/** Answers the tests on their own as passing, and a mutant's run by writing a guard that saw the copy run, this JUnit log, and ending so. */
function trialLogging(Project $at, string $log, Ran $mutant): ShellFake
{
    return new ShellFake(static function (Command $command) use ($at, $log, $mutant): Ran {
        $guard = $command->environment()['MUTATION_GATE_GUARD'] ?? false;

        if (! is_string($guard)) {
            return Ran::finished(succeeded: true, output: '');
        }

        file_put_contents($guard, '{"before":false,"loaded":true,"opcache":false}');
        file_put_contents(sprintf('%s/0/junit.xml', $at->root()), $log);

        return $mutant;
    });
}

/** A mutant Pest left uncovered on line 9 of src/Money.php, for a trial's outcome to judge. */
function trialMutant(): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'IncrementInteger', '-1 +2', 0),
        'n1',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('IncrementInteger', MutatorFamily::Arithmetic, '-1 +2'),
        MutantStatus::Uncovered,
        Unmeasured::duration(),
    );
}

it('names the tests its JUnit log says failed as the killers of the mutant it kills, and so gives no ending', function (): void {
    $at = trialProject();
    $shell = trialLogging($at, <<<'XML'
        <testsuites><testsuite name="T">
          <testcase name="it adds" file="tests/ASpec.php::it adds" class="Tests\ASpec"/>
          <testcase name="it subtracts" file="tests/ASpec.php::it subtracts" class="Tests\ASpec"><failure type="F">it subtractsFailed.</failure></testcase>
        </testsuite></testsuites>
        XML, Ran::exited(2, ''));

    $outcome = trialOf($at, $shell)->of(Paths::of(Path::of('tests/ASpec.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$outcome->judging(trialMutant())->killers()]))
        ->toBe(['P\Tests\ASpec::__pest_evaluable_it_subtracts'])
        ->and($outcome->evidence())->toEqual(Evidence::none());
});

it('names the first test its JUnit log says failed as the killer, and every one where the run records a full kill matrix', function (MatrixKind $matrix, array $killers): void {
    $at = trialProject();
    $shell = trialLogging($at, <<<'XML'
        <testsuites><testsuite name="T">
          <testcase name="it subtracts" file="tests/ASpec.php::it subtracts" class="Tests\ASpec"><failure type="F">x</failure></testcase>
          <testcase name="it divides" file="tests/ASpec.php::it divides" class="Tests\ASpec"><error type="E">x</error></testcase>
        </testsuite></testsuites>
        XML, Ran::exited(2, ''));

    $outcome = trialOf($at, $shell, matrix: $matrix)->of(Paths::of(Path::of('tests/ASpec.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$outcome->judging(trialMutant())->killers()]))->toBe($killers);
})->with([
    'first killers' => [MatrixKind::FirstKiller, ['P\Tests\ASpec::__pest_evaluable_it_subtracts']],
    'a full kill matrix' => [MatrixKind::Full, ['P\Tests\ASpec::__pest_evaluable_it_subtracts', 'P\Tests\ASpec::__pest_evaluable_it_divides']],
]);

it('gives a kill whose JUnit log names no failure how its run ended, a fatal error PHP printed among it', function (): void {
    $at = trialProject();
    $shell = trialLogging($at, '<testsuites><testsuite name="T"/></testsuites>', Ran::exited(255, "PHP Fatal error:  Cannot redeclare function helper()\n"));

    $outcome = trialOf($at, $shell)->of(Paths::of(Path::of('tests/ASpec.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $ended = $outcome->evidence()->ended();

    expect($outcome->status())->toBe(MutantStatus::Killed)
        ->and([...$outcome->judging(trialMutant())->killers()])->toBe([])
        ->and($ended instanceof Ended ? [$ended->code(), $ended->signalled(), $ended->fatal()] : $ended)->toBe([255, false, true]);
});

it('gives a kill a signal ended, which writes no guard, how its run ended', function (): void {
    $outcome = trialOf(trialProject(), new ShellFake(trialAnswering('', Ran::exited(139, ''))))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $ended = $outcome->evidence()->ended();

    expect($ended instanceof Ended ? [$ended->code(), $ended->signalled()] : $ended)->toBe([139, true]);
});

it('says the first test that failed on its own, from the JUnit log the run wrote, and the files it ran', function (): void {
    $at = trialProject();
    mkdir(sprintf('%s/0', $at->root()));
    $log = sprintf('%s/0/junit.xml', $at->root());
    file_put_contents($log, 'a log an earlier run left');
    $shell = new ShellFake(static function (Command $command) use ($log): Ran {
        file_put_contents($log, <<<'XML'
            <testsuites><testsuite name="T">
              <testcase name="it adds" file="tests/A.php::it adds" class="T"/>
              <testcase name="it subtracts" file="tests/A.php::it subtracts" class="T"><failure type="E">it subtractsFailed asserting that 1 is 2.
            at tests/A.php:9</failure></testcase>
            </testsuite></testsuites>
            XML);

        return Ran::exited(2, $command->arguments()[0]);
    });
    $tests = Paths::of(Path::of('tests/A.php'), Path::of('tests/B.php'), Path::of('tests/C.php'), Path::of('tests/D.php'));

    $outcome = trialOf($at, $shell)->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->reason())->toEqual(Reason::that(
        'the selected tests fail on their own (exit code 2; first failing test tests/A.php::it subtracts: '
        . 'Failed asserting that 1 is 2.; ran tests/A.php, tests/B.php, tests/C.php and 1 more)',
    ));
});

it('reads no failure from an earlier run\'s log where the tests fail on their own', function (): void {
    $at = trialProject();
    mkdir(sprintf('%s/0', $at->root()));
    file_put_contents(
        sprintf('%s/0/junit.xml', $at->root()),
        '<testsuites><testcase name="x" file="tests/Old.php::x"><failure>x</failure></testcase></testsuites>',
    );

    $outcome = trialOf($at, ShellFake::answering(Ran::exited(2, 'Fatal: half')))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->reason())->toEqual(Reason::that('the selected tests fail on their own (exit code 2; last printed Fatal: half; ran tests/A.php)'));
});

it('skips a mutant whose tests on their own run out of its limit, as too slow to run, running them alone once', function (): void {
    $shell = ShellFake::answering(Ran::stopped('Fatal: half')->took(Seconds::of(6.2)));
    $trial = trialOf(trialProject(), $shell);
    $tests = Paths::of(Path::of('tests/A.php'));

    $first = $trial->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $second = $trial->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($first)->toEqual(Outcome::skipped()->took(Seconds::of(6.2))->within(Seconds::of(6.0)))
        ->and($first->reason())->toEqual(Unreported::reason())
        ->and($second)->toEqual($first)
        ->and($shell->commands())->toHaveCount(1);
});

it('skips a mutant whose tests on their own were stopped untimed, as having taken its limit', function (): void {
    $outcome = trialOf(trialProject(), ShellFake::answering(Ran::stopped('')))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome)->toEqual(Outcome::skipped()->took(Seconds::of(6.0))->within(Seconds::of(6.0)));
});

it('says the last line a run printed where it failed and no test did', function (): void {
    $outcome = trialOf(trialProject(), ShellFake::answering(Ran::exited(255, "PHP Fatal error:  Allowed memory size exhausted\n\n")))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->reason())->toEqual(Reason::that(
        'the selected tests fail on their own (exit code 255; last printed PHP Fatal error: Allowed memory size exhausted; ran tests/A.php)',
    ));
});

it('judges nothing where the tests fail on their own, running them alone once for each set', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: ''));
    $at = trialProject();
    $trial = trialOf($at, $shell, Group::named('holds:src/Money.php'));
    $tests = Paths::of(Path::of('tests/A.php'), Path::of('tests/B.php'));

    $first = $trial->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $second = $trial->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($first)->toEqual(Outcome::unjudged('the selected tests fail on their own (no exit code; ran tests/A.php, tests/B.php)')->within(Seconds::of(6.0)))
        ->and($second)->toEqual($first)
        ->and($shell->commands())->toEqual([
            trialServed($at, trialJudging(sprintf('%s/0', $at->root()), $tests, Group::named('holds:src/Money.php'))->within(Seconds::of(6.0))),
        ]);
});

it('keeps each run to the limit it is given, and says that limit of what it found', function (): void {
    $at = trialProject();
    $shell = new ShellFake(trialAnswering('', Ran::stopped('')));
    $trial = trialOf($at, $shell);
    $a = Paths::of(Path::of('tests/A.php'));
    $b = Paths::of(Path::of('tests/B.php'));

    $first = $trial->of($a, Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $second = $trial->of($b, Path::of('src/Money.php'), '/c', Seconds::of(11.5));

    expect([$first->status(), $second->status()])->toBe([MutantStatus::TimedOut, MutantStatus::TimedOut])
        ->and([$first->limit(), $second->limit()])->toEqual([Seconds::of(6.0), Seconds::of(11.5)])
        ->and(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(6.0), Seconds::of(6.0), Seconds::of(11.5), Seconds::of(11.5)]);
});

function uncappedScan(Project $at): MemoryScan
{
    $scan = MemoryScan::beside($at, sprintf('%s/results.jsonl', $at->root()), MemoryCap::none(), new CapDirectory());

    return $scan instanceof MemoryScan ? $scan : throw new LogicException('An uncapped scan writes nothing.');
}

it('runs each new set of tests on its own for each file it judges, then the trials whose tests pass there, each batch side by side, each run writing in a directory of its own', function (): void {
    $at = trialProject();
    $shell = new ShellFake(static function (Command $command): Ran {
        $environment = $command->environment();
        $guard = array_key_exists('MUTATION_GATE_GUARD', $environment) ? $environment['MUTATION_GATE_GUARD'] : false;
        $alone = ! is_string($guard);
        $failing = in_array('tests/B.php', $command->arguments(), strict: true);

        if (is_string($guard)) {
            file_put_contents($guard, '{"before":false,"loaded":true,"opcache":false}');
        }

        return Ran::finished(succeeded: $alone && ! $failing, output: '');
    });
    $a = Paths::of(Path::of('tests/A.php'));
    $b = Paths::of(Path::of('tests/B.php'));
    $trial = trialOf($at, $shell);
    $runs = [
        TrialRun::of($a, Path::of('src/Money.php'), '/c1', Seconds::of(6.0)),
        TrialRun::of($b, Path::of('src/Money.php'), '/c2', Seconds::of(6.0)),
        TrialRun::of($a, Path::of('src/Tax.php'), '/c3', Seconds::of(6.0)),
    ];

    $outcomes = $trial->ofEach(...$runs);
    $guards = array_map(
        static fn(Command $command): string|false => $command->environment()['MUTATION_GATE_GUARD'],
        array_slice($shell->commands(), 3),
    );

    expect(array_map(static fn(Outcome $outcome): MutantStatus => $outcome->status(), $outcomes))
        ->toBe([MutantStatus::Killed, MutantStatus::Unjudged, MutantStatus::Killed])
        ->and($shell->batches())->toBe([3, 2])
        ->and($guards)->toBe([sprintf('%s/0/guard.json', $at->root()), sprintf('%s/1/guard.json', $at->root())])
        ->and($trial->ofEach(...$runs))->toHaveCount(3)
        ->and($shell->batches())->toBe([3, 2, 2]);
});

it('judges nothing of a mutant whose tests fail only while Pest\'s override serves a file, as they fail on their own with it served unmutated', function (): void {
    $at = trialProject();
    $served = static fn(Command $command): bool => ($command->environment()['PEST_MUTATION_FILE'] ?? false) !== false;
    $shell = new ShellFake(static fn(Command $command): Ran => Ran::finished(succeeded: ! $served($command), output: 'a dangling link stats as missing'));

    $outcome = trialOf($at, $shell)->of(Paths::of(Path::of('tests/LinkSpec.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::Unjudged)
        ->and($outcome->reason())->toEqual(Reason::that(
            'the selected tests fail on their own (no exit code; last printed a dangling link stats as missing; ran tests/LinkSpec.php)',
        ))
        ->and($shell->commands())->toHaveCount(1);
});

it('runs the same tests on their own again for each file they judge, served unmutated in its place', function (): void {
    $at = trialProject();
    $shell = new ShellFake(trialAnswering('{"before":false,"loaded":true,"opcache":false}', Ran::finished(succeeded: false, output: '')));
    $tests = Paths::of(Path::of('tests/A.php'));
    $trial = trialOf($at, $shell);

    $trial->ofEach(
        TrialRun::of($tests, Path::of('src/Money.php'), '/c1', Seconds::of(6.0)),
        TrialRun::of($tests, Path::of('src/Tax.php'), '/c2', Seconds::of(6.0)),
    );
    $alone = array_slice($shell->commands(), 0, 2);

    expect(array_map(static fn(Command $command): string|false => $command->environment()['PEST_MUTATION_FILE'], $alone))
        ->toBe([trialUnmutated($at, 'src/Money.php'), trialUnmutated($at, 'src/Tax.php')])
        ->and(file_get_contents(trialUnmutated($at, 'src/Tax.php')))->toBe("<?php\n\nfinal class Tax\n{\n}");
});

it('judges nothing, and runs nothing, where the file the tests judge cannot be served unmutated', function (string $source, string $why): void {
    $at = trialProject();
    Scratch::write($at->root(), 'src/Broken.php', "<?php\n\nfinal class {\n");
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $outcome = trialOf($at, $shell)->of(Paths::of(Path::of('tests/A.php')), Path::of($source), '/c', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::Unjudged)
        ->and($outcome->reason() instanceof Reason ? $outcome->reason()->text() : '')
        ->toStartWith(sprintf('the selected tests fail on their own (%s', $why))
        ->and($shell->commands())->toBe([]);
})->with([
    'gone' => ['src/Gone.php', 'The gate cannot read src/Gone.php to serve it unmutated.'],
    'no longer parsing' => ['src/Broken.php', 'src/Broken.php does not parse'],
]);

it('says of a mutant its tests that passed on their own, unmutated, took this long there, by the JUnit log of that run', function (): void {
    $at = trialProject();
    $log = sprintf('%s/0/junit.xml', $at->root());
    $shell = new ShellFake(static function (Command $command) use ($log): Ran {
        $mutated = ($command->environment()['PEST_MUTATION_FILE'] ?? '') === '/c';
        file_put_contents($log, $mutated ? '<testsuites/>' : <<<'XML'
            <testsuites><testsuite name="T" time="9.0">
              <testcase name="it adds" file="tests/A.php::it adds" time="0.25"/>
              <testcase name="it subtracts" file="tests/A.php::it subtracts" time="0.5"/>
            </testsuite></testsuites>
            XML);

        return $mutated ? Ran::stopped('') : Ran::finished(succeeded: true, output: '');
    });
    $tests = Paths::of(Path::of('tests/A.php'));

    $outcome = trialOf($at, $shell)->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));
    $again = trialOf($at, new ShellFake(trialAnswering('', Ran::stopped(''))))
        ->of($tests, Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::TimedOut)
        ->and($outcome->need())->toEqual(Seconds::of(0.75))
        ->and($again->need())->toEqual(Unmeasured::duration());
});
