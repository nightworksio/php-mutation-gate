<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\OpeningIssues;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** What Pest printed of an opening run that passed its one test, where the run then stopped. */
const OPENING_PASSED = "  .\n\n  Tests:    1 passed (1 assertions)\n  Duration: 10.37s\n";

/** How every message of such a run begins. */
const OPENING_ENDED = "Pest's opening run failed no test, yet ended with exit code 1, so Pest made no mutant. "
    . 'PHPUnit fails such a run on a warning, deprecation or notice where phpunit.xml says to.';

/** A project in a new directory, by its real path, and the results file its runs record to. */
function openingProject(): Project
{
    return Project::at((string) realpath(Scratch::directory()), Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
}

function openingResults(Project $project): string
{
    return sprintf('%s/.mutation-gate/pest/results.jsonl', $project->root());
}

/** A request to mutate src/Money.php against a group, with a variable withheld and one suite's tests. */
function openingRequest(): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('money'));
}

/** An opening run that exited 1 after Pest printed this, having taken ten seconds. */
function openingRan(string $said = OPENING_PASSED): Ran
{
    return Ran::exited(1, $said)->took(Seconds::of(10.0));
}

/** A shell whose run of the opening tests logs these lines of PHPUnit's events, and ends so. */
function openingShell(string $log, Ran|false $ends = false): ShellFake
{
    return new ShellFake(static function (Command $command) use ($log, $ends): Ran {
        foreach ($command->arguments() as $argument) {
            if (str_starts_with($argument, '--log-events-text=') && $log !== '') {
                Scratch::write('/', substr($argument, strlen('--log-events-text=')), $log);
            }
        }

        return $ends instanceof Ran ? $ends : Ran::exited(1, 'the opening tests again');
    });
}

/** The interpretation of a run, which runs its opening tests again through this shell. */
function openingRead(Project $project, ShellFake $shell, Ran $ran, Seconds|Unlimited $left = new Unlimited()): MutationResult|CannotJudge
{
    $results = openingResults($project);
    $opening = OpeningIssues::of($shell, $project, openingRequest(), Group::named('money'), static fn(): Seconds|Unlimited => $left, $results);

    return new Interpretation($project, Patching::off(), MemoryCap::standard(), opening: $opening)
        ->of($ran, $results, CoverageFile::at(Recorder::coverageBeside($results)));
}

it('quotes each issue the opening tests raise run again alone, with where it was raised', function (): void {
    $project = openingProject();
    $shell = openingShell(implode("\n", [
        'Test Runner Started',
        sprintf('Test Runner Triggered PHP Warning () in %s/tests/JudgingTest.php:151', $project->root()),
        "The use statement with non-compound name 'PHP_BINARY' has no effect",
        'Test Runner Triggered PHPUnit Deprecation (The --foo option is deprecated)',
        sprintf('Test Triggered Deprecation (P\Tests\MoneySpec::adds) in %s/src/Money.php:9', $project->root()),
        "round() is \e[31mdeprecated",
        'Test Runner Finished',
    ]));

    expect(openingRead($project, $shell, openingRan()))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests raised:\n  %s\n  %s\n  %s\n Pest said:\n%s",
        OPENING_ENDED,
        "PHP Warning in tests/JudgingTest.php:151: The use statement with non-compound name 'PHP_BINARY' has no effect",
        'PHPUnit Deprecation, raised by the runner: The --foo option is deprecated',
        'Deprecation in src/Money.php:9: round() is [31mdeprecated',
        OPENING_PASSED,
    )))->and($shell->commands())->toEqual([
        Invocation::installedIn(Path::of('vendor'))
            ->opening(openingRequest(), Group::named('money'), sprintf('%s.events', openingResults($project)))
            ->within(Seconds::of(15.0)),
    ]);
});

it('quotes an issue PHPUnit raised in a test by the test, and none it was told to ignore or that was suppressed', function (): void {
    $project = openingProject();
    $shell = openingShell(implode("\n", [
        'Test Triggered PHPUnit Warning (P\Tests\MoneySpec::adds)',
        'No assertion was performed',
        sprintf('Test Triggered PHP Deprecation (P\Tests\MoneySpec::adds, ignored by baseline) in %s/src/Money.php:9', $project->root()),
        'round() is deprecated',
        sprintf('Test Triggered Warning (P\Tests\MoneySpec::adds, suppressed using operator) in %s/src/Money.php:10', $project->root()),
        'fopen failed',
        'Test Triggered PHPUnit Notice (P\Tests\MoneySpec::adds, ignored by test)',
        'noted',
    ]));

    expect(openingRead($project, $shell, openingRan()))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests raised:\n  %s\n Pest said:\n%s",
        OPENING_ENDED,
        'PHPUnit Warning in P\Tests\MoneySpec::adds: No assertion was performed',
        OPENING_PASSED,
    )));
});

it('quotes an issue raised again once, and the first few, saying how many more', function (): void {
    $project = openingProject();
    $warning = static fn(int $line): string => sprintf("Test Runner Triggered PHP Warning () in %s/a.php:%d\nsaid", $project->root(), $line);
    $shell = openingShell(implode("\n", [$warning(1), $warning(1), $warning(2), $warning(3), $warning(4), $warning(5)]));

    expect(openingRead($project, $shell, openingRan()))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests raised:\n  %s\n Pest said:\n%s",
        OPENING_ENDED,
        "PHP Warning in a.php:1: said\n  PHP Warning in a.php:2: said\n  PHP Warning in a.php:3: said\n  And 2 more.",
        OPENING_PASSED,
    )))->and(openingRead($project, openingShell(implode("\n", [$warning(1), $warning(2), $warning(3), $warning(4)])), openingRan()))
        ->toEqual(CannotJudge::because(sprintf(
            "%s Run again alone, the opening tests raised:\n  %s\n Pest said:\n%s",
            OPENING_ENDED,
            "PHP Warning in a.php:1: said\n  PHP Warning in a.php:2: said\n  PHP Warning in a.php:3: said\n  And 1 more.",
            OPENING_PASSED,
        )));
});

it('says the opening tests could not show the issues where they did not finish again in time', function (): void {
    $project = openingProject();
    $shell = openingShell('Test Runner Triggered PHPUnit Warning (logged before the stop)', Ran::stopped('stopped'));

    expect(openingRead($project, $shell, openingRan(), Seconds::of(7.0)))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests could not show which: they did not finish in the time they had. Pest said:\n%s",
        OPENING_ENDED,
        OPENING_PASSED,
    )))->and($shell->commands()[0]->deadline())->toEqual(Seconds::of(7.0));
});

it('says the opening tests could not show the issues where they wrote no log, or logged none', function (): void {
    $unlogged = openingProject();
    $quiet = openingShell("Test Runner Started\nTest Runner Finished");

    expect(openingRead($unlogged, openingShell(''), openingRan()))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests could not show which: they wrote no log of PHPUnit's events. Pest said:\n%s",
        OPENING_ENDED,
        OPENING_PASSED,
    )))->and(openingRead(openingProject(), $quiet, openingRan()))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests could not show which: PHPUnit logged no warning, deprecation or notice. Pest said:\n%s",
        OPENING_ENDED,
        OPENING_PASSED,
    )));
});

it('runs the opening tests again where they passed but skipped, left incomplete or found risky some, and says a run with no exit code', function (): void {
    $said = "  Tests:    1 risky, 2 skipped, 1 incomplete, 3 passed (3 assertions)\n";
    $shell = openingShell("Test Runner Started\nTest Runner Finished");

    expect(openingRead(openingProject(), $shell, Ran::finished(succeeded: false, output: $said)))->toEqual(CannotJudge::because(sprintf(
        "%s Run again alone, the opening tests could not show which: PHPUnit logged no warning, deprecation or notice. Pest said:\n%s",
        str_replace('with exit code 1', 'with no exit code', OPENING_ENDED),
        $said,
    )))->and($shell->commands())->toHaveCount(1);
});

it('keeps what Pest said of a run whose tests failed, and runs nothing again', function (): void {
    $shell = openingShell('');
    $said = "  Tests:    1 failed, 2 passed (3 assertions)\n";

    expect(openingRead(openingProject(), $shell, openingRan($said)))
        ->toEqual(CannotJudge::because(sprintf("Pest's mutation run failed. Pest said:\n%s", $said)))
        ->and($shell->commands())->toBe([]);
});

it('runs nothing again for a run that recorded mutants, summed them up, printed no tests, or was stopped', function (): void {
    $recorded = openingProject();
    Scratch::write($recorded->root(), '.mutation-gate/pest/results.jsonl', sprintf("%s\n", PestRun::made(1)));
    $shell = openingShell('');

    openingRead($recorded, $shell, openingRan());
    openingRead(openingProject(), $shell, openingRan(sprintf("%s  Mutations: 1 tested\n", OPENING_PASSED)));
    openingRead(openingProject(), $shell, openingRan('PHP Fatal error: out of luck'));
    openingRead(openingProject(), $shell, Ran::stopped(OPENING_PASSED));

    expect($shell->commands())->toBe([]);
});

it('gives the run again Pest\'s limit for the opening run\'s seconds, or the time left where that is less', function (): void {
    $limit = static function (Ran $ran, Seconds|Unlimited $left): Seconds|Unlimited {
        $shell = openingShell('', Ran::stopped('stopped'));
        openingRead(openingProject(), $shell, $ran, $left);

        return $shell->commands()[0]->deadline();
    };

    expect($limit(openingRan(), Unlimited::time()))->toEqual(Seconds::of(15.0))
        ->and($limit(openingRan(), Seconds::of(20.0)))->toEqual(Seconds::of(15.0))
        ->and($limit(openingRan(), Seconds::of(15.0)))->toEqual(Seconds::of(15.0))
        ->and($limit(openingRan(), Seconds::of(14.0)))->toEqual(Seconds::of(14.0))
        ->and($limit(Ran::exited(1, OPENING_PASSED), Seconds::of(9.0)))->toEqual(Seconds::of(9.0))
        ->and($limit(Ran::exited(1, OPENING_PASSED), Unlimited::time()))->toEqual(Unlimited::time());
});

it('runs the opening tests again alone as the run selected them, withholding what it withholds, and logging PHPUnit\'s events', function (): void {
    $request = openingRequest();

    expect(Invocation::installedIn(Path::of('vendor'))->opening($request, WholeSuite::tests(), '/x/results.jsonl.events'))
        ->toEqual(Command::pest(
            'vendor/pestphp/pest/bin/pest',
            $request->withheld(),
            '--no-tia',
            '--colors=never',
            '--log-events-text=/x/results.jsonl.events',
        ))
        ->and(Invocation::installedIn(Path::of('vendor'))->opening($request, Group::named('mutation-canary'), '/e'))
        ->toEqual(Command::pest(
            'vendor/pestphp/pest/bin/pest',
            $request->withheld(),
            '--no-tia',
            '--colors=never',
            '--log-events-text=/e',
            '--group=mutation-canary',
            '--do-not-fail-on-empty-test-suite',
        ));
});

it('reads as ended after the opening run only a run that failed, and was not stopped', function (): void {
    $project = openingProject();
    $opening = OpeningIssues::of(openingShell(''), $project, openingRequest(), Group::named('money'), static fn(): Unlimited => new Unlimited(), openingResults($project));

    expect($opening->endedAfterOpening(openingRan()))->toBeTrue()
        ->and($opening->endedAfterOpening(Ran::exited(0, OPENING_PASSED)))->toBeFalse()
        ->and($opening->endedAfterOpening(Ran::stopped(OPENING_PASSED)))->toBeFalse();
});

it('runs the opening tests again in the suite the run is narrowed to, withholding what the run withholds', function (): void {
    $request = openingRequest()
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toSuite(SuiteName::of('Unit')))
        ->withholding(Withheld::of('DEPLOY_*'));

    $opening = Environment::during(
        ['DEPLOY_KEY' => 'secret'],
        static fn(): Command => Invocation::installedIn(Path::of('vendor'))->opening($request, WholeSuite::tests(), '/e'),
    );

    expect($opening->arguments())->toBe([
        PHP_BINARY,
        'vendor/pestphp/pest/bin/pest',
        '--no-tia',
        '--colors=never',
        '--log-events-text=/e',
        '--testsuite=Unit',
    ])->and($opening->environment())->toHaveKey('DEPLOY_KEY', value: false);
});
