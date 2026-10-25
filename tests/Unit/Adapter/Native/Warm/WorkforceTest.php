<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Invocation;
use NightWorksIO\MutationGate\Adapter\Native\MutantRun;
use NightWorksIO\MutationGate\Adapter\Native\MutationRun;
use NightWorksIO\MutationGate\Adapter\Native\PreparedRun;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\WallClock;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Refusal;
use NightWorksIO\MutationGate\Adapter\Native\Warm\WarmRun;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Workforce;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitScan;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitWorkersFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\WithoutWorkers;

afterEach(function (): void {
    Scratch::sweep();
});

/** A library whose Money echoes and adds, and whose Tax nothing covers. */
function warmLibrary(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    echo 'adding';\n\n    return \$a + \$b;\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfunction tax(\$a)\n{\n    return \$a + 1;\n}\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
}

/** A run that kills its mutant: its covering test fails, and its mutant was served. */
function warmKilled(string $results, string $guard): Ran
{
    file_put_contents($results, "failed Tests%5CMoneySpec%3A%3Aadds\n");
    file_put_contents($guard, "served\n");

    return Ran::finished(succeeded: false, output: '');
}

/** A shell whose workers' children and fresh runs each kill their mutant. */
function killingWorkers(): PhpUnitWorkersFake
{
    return new PhpUnitWorkersFake(
        static fn(WarmRun $run): Ran => warmKilled($run->environment()[Variable::Results->value], $run->guard()),
        static function (Command $command): Ran {
            $environment = $command->environment();

            return warmKilled($environment[Variable::Results->value], $environment[Variable::Guard->value]);
        },
    );
}

/**
 * Runs for this many nanoseconds without sleeping: a child that runs past the
 * deadline, for which the time passes for real, as the workers read the wall
 * clock. It says how many times it asked the clock.
 */
function warmRunningFor(int $nanoseconds): int
{
    $until = hrtime(as_number: true) + $nanoseconds;
    $asked = 1;

    while (hrtime(as_number: true) < $until) {
        $asked++;
    }

    return $asked;
}

/** A mutation run of the project's PHPUnit, or of the framework given in its place. */
function warmMutation(Project $project, PhpUnitWorkersFake $shell, Framework|NotGiven $framework = new NotGiven()): MutationRun
{
    $framework = $framework instanceof Framework ? $framework : NativePhpUnit::in($project);
    $invocation = new Invocation($project, '/gate/override.php', $framework);
    $scan = PhpUnitScan::uncapped($project);
    $judging = new MutantRun($project, $shell, $invocation, $framework, $scan, NotGiven::value());

    return new MutationRun(
        $project,
        Engine::with(new PlusToMinus(), new RemoveEcho()),
        $judging,
        new Workforce($project, $shell, $invocation, $scan, $judging, $framework),
        Laps::from(new WallClock()->seconds(...)),
    );
}

/** @param positive-int $processes */
function forking(int $processes): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->across(Pool::of(ProcessCount::of($processes), Workers::Fork));
}

/** @return list<array{string, string}> each mutant's mutator and status, in the order judged */
function warmVerdicts(MutationResult|CannotJudge $result): array
{
    return $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): array => [$mutant->mutator(), $mutant->status()->value],
        [...$result->mutants()],
    ) : [];
}

/** @return list<string> */
function warmWarnings(MutationResult|CannotJudge $result): array
{
    return $result instanceof MutationResult
        ? array_map(static fn(Warning $warning): string => $warning->text(), [...$result->warnings()])
        : ['cannot judge'];
}

$makeAddsOnly = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('Tests\MoneySpec::adds'));
$makeBoth = static fn(): CoverageMap => $makeAddsOnly()->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('Tests\MoneySpec::echoes'));

it('judges each run a worker\'s child ran at its own place in the queue, past the mutants with no run, and starts none fresh', function () use ($makeAddsOnly): void {
    $addsOnly = $makeAddsOnly();

    $shell = killingWorkers();
    $result = warmMutation(warmLibrary(), $shell)->of(forking(1), $addsOnly, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmVerdicts($result))->toBe([
        ['acme/RemoveEcho', 'uncovered'],
        ['acme/PlusToMinus', 'killed'],
        ['acme/PlusToMinus', 'uncovered'],
    ])
        ->and($shell->children())->toHaveCount(1)
        ->and($shell->fresh())->toBe([])
        ->and(warmWarnings($result))->toBe([]);
});

it('starts one worker in each of the request\'s places, the runs between them, and tells each child PHPUnit\'s command line from its script on', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    $shell = killingWorkers();
    $result = warmMutation($project, $shell)->of(forking(2), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmVerdicts($result))->toBe([
        ['acme/RemoveEcho', 'killed'],
        ['acme/PlusToMinus', 'killed'],
        ['acme/PlusToMinus', 'uncovered'],
    ])
        ->and($shell->workers())->toHaveCount(2)
        ->and($shell->fresh())->toBe([])
        ->and(array_map(static fn(WarmRun $run): string => $run->argv()[0], $shell->children()))
        ->toBe([NativePhpUnit::in($project)->script(), NativePhpUnit::in($project)->script()])
        ->and(array_map(static fn(WarmRun $run): string => $run->original(), $shell->children()))
        ->toBe([$project->absolute(Path::of('src/Money.php')), $project->absolute(Path::of('src/Money.php'))]);
});

it('starts no worker for a framework that has none, and runs each mutant fresh', function () use ($makeAddsOnly): void {
    $project = warmLibrary();
    $shell = killingWorkers();
    $result = warmMutation($project, $shell, new WithoutWorkers(NativePhpUnit::in($project)))
        ->of(forking(2), $makeAddsOnly(), LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmVerdicts($result))->toBe([
        ['acme/RemoveEcho', 'uncovered'],
        ['acme/PlusToMinus', 'killed'],
        ['acme/PlusToMinus', 'uncovered'],
    ])
        ->and($shell->workers())->toBe([])
        ->and($shell->fresh())->toHaveCount(1)
        ->and(warmWarnings($result))->toBe([]);
});

it('runs fresh each run no worker claimed, in its place in the queue', function () use ($makeBoth): void {
    $both = $makeBoth();

    $shell = killingWorkers()->claimingAtMost(1);
    $result = warmMutation(warmLibrary(), $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmVerdicts($result))->toBe([
        ['acme/RemoveEcho', 'killed'],
        ['acme/PlusToMinus', 'killed'],
        ['acme/PlusToMinus', 'uncovered'],
    ])
        ->and($shell->children())->toHaveCount(1)
        ->and($shell->fresh())->toHaveCount(1)
        ->and(warmWarnings($result))->toBe([]);
});

it('warns once of a refused boot however many workers it refused, runs each mutant fresh, and keeps the reason for doctor', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    $shell = killingWorkers()->booting(Refusal::guarded('The boot left a socket open.'));
    $result = warmMutation($project, $shell)->of(forking(2), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmWarnings($result))->toBe(['The boot left a socket open.'])
        ->and($shell->children())->toBe([])
        ->and($shell->fresh())->toHaveCount(2)
        ->and(file_get_contents($project->own(WarmRefusal::NAME)))->toBe('The boot left a socket open.');
});

it('forgets the kept reason once the workers fork', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    $shell = killingWorkers();
    warmMutation($project, $shell->booting(Refusal::guarded('The boot left a socket open.')))->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    warmMutation($project, $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(is_file($project->own(WarmRefusal::NAME)))->toBeFalse();
});

it('runs each mutant fresh, and warns of nothing, where the PHP the runner starts cannot fork', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    $shell = killingWorkers()->booting(Refusal::unforkable('pcntl is not loaded.'));
    $result = warmMutation($project, $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmWarnings($result))->toBe([])
        ->and($shell->fresh())->toHaveCount(2)
        ->and(is_file($project->own(WarmRefusal::NAME)))->toBeFalse();
});

it('warns of a worker that failed with all it said, and runs the mutants it left fresh', function () use ($makeBoth): void {
    $both = $makeBoth();

    $shell = killingWorkers()->booting(Ran::exited(255, "\nPHP Fatal error: bootstrap.php broke\n"));
    $result = warmMutation(warmLibrary(), $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(warmWarnings($result))
        ->toBe(['A warm worker failed, so the mutants it left ran fresh. It said: PHP Fatal error: bootstrap.php broke'])
        ->and(warmVerdicts($result))->toBe([
            ['acme/RemoveEcho', 'killed'],
            ['acme/PlusToMinus', 'killed'],
            ['acme/PlusToMinus', 'uncovered'],
        ])
        ->and($shell->fresh())->toHaveCount(2);
});

it('gives its workers no deadline where the request has none, and the time left, the longest limit and a minute where it has', function () use ($makeBoth): void {
    $both = $makeBoth();

    $unlimited = killingWorkers();
    $limited = killingWorkers();
    warmMutation(warmLibrary(), $unlimited)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $started = hrtime(as_number: true);
    warmMutation(warmLibrary(), $limited)->of(forking(1)->within(Seconds::of(100.0)), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $spent = (hrtime(as_number: true) - $started) / Seconds::NANOSECONDS;
    $deadline = $limited->workers()[0]->deadline();

    expect($unlimited->workers()[0]->deadline())->toEqual(Unlimited::time())
        ->and($deadline instanceof Seconds ? $deadline->seconds() : 0.0)
        ->toBeGreaterThanOrEqual(165.0 - $spent)
        ->toBeLessThanOrEqual(165.0);
});

it('starts no worker where no mutant has a run', function (): void {
    $shell = killingWorkers();
    $result = warmMutation(warmLibrary(), $shell)->of(forking(1), CoverageMap::empty(), LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->commands())->toBe([])
        ->and(warmVerdicts($result))->toHaveCount(3);
});

it('removes the workplace it made once its workers are done', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    warmMutation($project, killingWorkers())->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(glob($project->own('warm/*')))->toBe([]);
});

it('runs each mutant fresh, and warns of nothing, where it cannot make its workplace', function () use ($makeBoth): void {
    $both = $makeBoth();

    $project = warmLibrary();
    $project->written('warm', 'a file where the workplaces go');
    $shell = killingWorkers();
    $result = warmMutation($project, $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->workers())->toBe([])
        ->and($shell->fresh())->toHaveCount(2)
        ->and(warmWarnings($result))->toBe([]);
});

it('claims no run once the request\'s deadline has passed, and leaves it unstarted', function () use ($makeBoth): void {
    $both = $makeBoth();

    $slow = new PhpUnitWorkersFake(
        static function (WarmRun $run): Ran {
            warmRunningFor(600_000_000);

            return warmKilled($run->environment()[Variable::Results->value], $run->guard());
        },
        static fn(): Ran => Ran::finished(succeeded: true, output: ''),
    );
    $result = warmMutation(warmLibrary(), $slow)->of(forking(1)->within(Seconds::of(0.4)), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($slow->children())->toHaveCount(1)
        ->and($slow->fresh())->toBe([])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(1);
});

it('gives each kill a worker\'s child made how far its run went, from the test its results file says started', function () use ($makeBoth): void {
    $both = $makeBoth();

    $started = static function (string $results, string $guard): Ran {
        $ran = warmKilled($results, $guard);
        file_put_contents($results, sprintf("started Tests%%5CMoneySpec%%3A%%3Aadds\n%s", (string) file_get_contents($results)));

        return $ran;
    };
    $shell = new PhpUnitWorkersFake(
        static fn(WarmRun $run): Ran => $started($run->environment()[Variable::Results->value], $run->guard()),
        static fn(Command $command): Ran => $started($command->environment()[Variable::Results->value], $command->environment()[Variable::Guard->value]),
    );

    $result = warmMutation(warmLibrary(), $shell)->of(forking(1), $both, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $prefixes = $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): Prefix|NotGiven => $result->evidence()->of($mutant->id())->prefix(),
        array_values(array_filter([...$result->mutants()], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed)),
    ) : [];
    $expected = Prefix::keyedAt(1, Prefix::keyOf(Paths::none(), OrderDigest::of(TestId::of('Tests\MoneySpec::adds'))->value()));

    expect($prefixes)->toEqual([$expected, $expected]);
});


/**
 * The deadline the workers are given for Money's two mutants, their runs
 * allowed these limits, with the end at this reading of `hrtime`.
 */
function warmDeadline(int|float $end, float $first, float $second): Seconds|Unlimited
{
    $project = warmLibrary();
    $shell = killingWorkers();
    $framework = NativePhpUnit::in($project);
    $invocation = new Invocation($project, '/gate/override.php', $framework);
    $scan = PhpUnitScan::uncapped($project);
    $run = new MutantRun($project, $shell, $invocation, $framework, $scan, NotGiven::value());
    $made = Engine::with(new PlusToMinus(), new RemoveEcho())->mutantsOf(Path::of('src/Money.php'), Contents::of((string) file_get_contents($project->absolute(Path::of('src/Money.php')))));
    $runs = [];

    $limits = [$first, $second];

    foreach ($made instanceof MadeMutants ? [...$made] : [] as $at => $mutant) {
        $prepared = $run->prepared($mutant, TestIds::of(TestId::of('Tests\MoneySpec::adds')), forking(1), Seconds::of($limits[$at]));
        $runs[] = $prepared instanceof PreparedRun ? $prepared : throw new LogicException('Money\'s mutant has no run.');
    }

    new Workforce($project, $shell, $invocation, $scan, $run, $framework)->judged(forking(1), $end, $runs);

    return $shell->workers()[0]->deadline();
}

it('gives its workers the time left and the longest of the runs\' limits, the time left none once the end has passed', function (): void {
    $started = hrtime(as_number: true);
    $ahead = warmDeadline($started + Seconds::of(100.0)->nanoseconds(), 20.0, 5.0);
    $spent = (hrtime(as_number: true) - $started) / Seconds::NANOSECONDS;

    expect($ahead instanceof Seconds ? [$ahead->seconds() >= 180.0 - $spent, $ahead->seconds() <= 180.0] : $ahead)->toBe([true, true])
        ->and(warmDeadline(hrtime(as_number: true) - Seconds::of(1.0)->nanoseconds(), 5.0, 20.0))->toEqual(Seconds::of(80.0));
});
