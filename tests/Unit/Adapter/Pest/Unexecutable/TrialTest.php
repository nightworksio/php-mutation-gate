<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Trial;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in a new directory, where a guard would be written. */
function trialProject(): Project
{
    return Project::at((string) realpath(Scratch::directory()), Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
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
function trialOf(Project $at, ShellFake $shell, WholeSuite|Group $judgedBy = new WholeSuite()): Trial
{
    return new Trial(
        $at,
        $shell,
        Invocation::installedIn(Path::of('vendor')),
        $judgedBy,
        Withheld::standard(),
        sprintf('%s/guard.json', $at->root()),
        uncappedScan($at),
    );
}

it('runs the tests with Pest\'s override serving the mutated copy, and a guard, within the limit it is given', function (): void {
    $at = trialProject();
    $shell = new ShellFake(trialAnswering('{"before":false,"loaded":true,"opcache":false}', Ran::finished(succeeded: false, output: '')));
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));
    $judging = trialJudging($at->root(), $tests);

    $outcome = trialOf($at, $shell)->of($tests, Path::of('src/Money.php'), '/copies/n1.php', Seconds::of(6.0));

    expect($outcome->status())->toBe(MutantStatus::Killed)
        ->and($outcome->duration())->toBeInstanceOf(Seconds::class)
        ->and($shell->commands())->toEqual([
            $judging->within(Seconds::of(6.0)),
            $judging->within(Seconds::of(6.0))->with([
                'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
                'PEST_MUTATION_FILE' => '/copies/n1.php',
                'MUTATION_GATE_GUARD' => sprintf('%s/guard.json', $at->root()),
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
    file_put_contents(sprintf('%s/guard.json', $at->root()), '{"before":false,"loaded":true,"opcache":false}');
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

it('says the first test that failed on its own, from the JUnit log the run wrote, and the files it ran', function (): void {
    $at = trialProject();
    $log = sprintf('%s/junit.xml', $at->root());
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

it('says a run on its own was stopped at its limit, and reads no failure from an earlier run\'s log', function (): void {
    $at = trialProject();
    file_put_contents(
        sprintf('%s/junit.xml', $at->root()),
        '<testsuites><testcase name="x" file="tests/Old.php::x"><failure>x</failure></testcase></testsuites>',
    );

    $outcome = trialOf($at, ShellFake::answering(Ran::stopped('Fatal: half')))
        ->of(Paths::of(Path::of('tests/A.php')), Path::of('src/Money.php'), '/c', Seconds::of(6.0));

    expect($outcome->reason())->toEqual(Reason::that('the selected tests fail on their own (stopped at its limit of 6s; last printed Fatal: half; ran tests/A.php)'));
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
            trialJudging($at->root(), $tests, Group::named('holds:src/Money.php'))->within(Seconds::of(6.0)),
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
