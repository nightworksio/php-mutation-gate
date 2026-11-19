<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Placement;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\FreshRuns;
use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\Cost\Laps;
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
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Workers;
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

/** The mutant of `src/Money.php` that subtracts, left unjudged as preloaded. */
function freshRaceMutant(): Mutant
{
    $diff = "--- Original\n+++ New\n@@ @@\n {\n-    return 1 + 1;\n+    return 1 - 1;\n }";

    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Unjudged,
        Unmeasured::duration(),
    )->because(Reason::preloaded(Path::of('src/Money.php')));
}

/** The test of `tests/MoneySpec.php` named so, which covers the second line of `src/Money.php`. */
function freshRaceTest(string $name): TestId
{
    return TestId::of(sprintf('P\Tests\MoneySpec::__pest_evaluable_it_%s', $name));
}

/**
 * A shell whose runs end by what each is: the fresh run itself (`run`), which takes long, the part of its tests raced
 * beside it that holds `it adds` (`adds`) or `it subtracts` (`subtracts`), which are quick, or a part's unmutated run
 * (`control`). Each of the first three fails the test this names for it, or passes where it names none; the control
 * passes as said. The fresh run ends otherwise where this says how (see freshRaceEnd()).
 *
 * @param array<string, string> $failing the test each fails, by what it is
 */
function freshRaceShell(array $failing, bool $controlPasses, string $runEnds = ''): ShellFake
{
    return new ShellFake(static function (Command $command) use ($failing, $controlPasses, $runEnds): Ran {
        $environment = $command->environment();
        $guard = (string) ($environment[Variable::Guard->value] ?? '');
        $mutated = (string) ($environment[Variable::Mutated->value] ?? '');
        $kind = match (true) {
            str_ends_with($mutated, 'unmutated.php') => 'control',
            str_contains($guard, '/race-0/') => 'adds',
            str_contains($guard, '/race-1/') => 'subtracts',
            default => 'run',
        };
        $took = Seconds::of($kind === 'run' ? 100.0 : 1.0);

        if ($kind === 'control') {
            return Ran::finished(succeeded: $controlPasses, output: '')->took($took);
        }

        if ($kind === 'run' && $runEnds !== '') {
            return freshRaceEnd($runEnds, $guard)->took($took);
        }

        file_put_contents($guard, sprintf("%s\n", Guard::SERVED));
        $fails = $failing[$kind] ?? '';

        if ($fails !== '') {
            JUnitLogs::failing($command->arguments(), sprintf('it %s', $fails), sprintf('tests/MoneySpec.php::it %s', $fails));

            return Ran::finished(succeeded: false, output: 'FAILED')->took($took);
        }

        JUnitLogs::passing($command->arguments(), 'it adds', 'tests/MoneySpec.php::it adds');

        return Ran::finished(succeeded: true, output: '')->took($took);
    });
}

/**
 * A fresh run that ends other than by its tests: stopped at its limit with the mutant served (`stopped`), failed on
 * exactly the memory cap (`memory`), or ended before it served the mutant (`unserved`).
 */
function freshRaceEnd(string $ends, string $guard): Ran
{
    if ($ends !== 'unserved') {
        file_put_contents($guard, sprintf("%s\n", Guard::SERVED));
    }

    return match ($ends) {
        'stopped' => Ran::stopped(''),
        'memory' => Ran::finished(
            succeeded: false,
            output: sprintf('Allowed memory size of %d bytes exhausted', MemoryCap::standard()->bytes()),
        ),
        default => Ran::finished(succeeded: true, output: ''),
    };
}

/**
 * The mutant judged again in a pool of three places through this shell, under `pest.freshRace` as given, with the
 * commands the shell was given.
 *
 * @return array{Mutant, list<Command>}
 */
function freshRaceJudged(ShellFake $shell, bool $racing): array
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $at = Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(2), freshRaceTest('adds'), freshRaceTest('subtracts'))
        ->timed(freshRaceTest('adds'), Seconds::of(0.1))
        ->timed(freshRaceTest('subtracts'), Seconds::of(50.0));
    $placement = Placement::inPestRunner($at->root(), Paths::none(), Path::of('vendor'), Path::of('.gate'));
    $patching = Patching::on(Group::named('canary'))->stepping(PestSteps::of(
        Toggles::of(Toggle::PestFreshRace, $racing),
        NarrowedKillTrust::Vouch,
        LongFilter::Files,
        Duplicates::JudgeOnce,
        preloaded: Preloaded::Fresh,
    ));
    $judged = new FreshRuns($at, $placement, $shell, new CapDirectory(), $patching, LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0)))
        ->judged(
            MutationResult::skippingNone(Mutants::of(freshRaceMutant())),
            MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
                ->across(Pool::of(ProcessCount::of(3), Workers::Fresh))
                ->cappedAt(MemoryCap::standard()),
            new HandedOver($map, $at),
            static fn(): Unlimited => Unlimited::time(),
            Laps::since(static fn(): Seconds => Seconds::of(0.0), Seconds::of(0.0)),
        );

    return [[...$judged->mutants()][0], $shell->commands()];
}

it('settles a mutant by the part raced beside its fresh run that kills it first, once that part passes unmutated', function (): void {
    [$mutant, $commands] = freshRaceJudged(freshRaceShell(['run' => 'subtracts', 'adds' => 'adds'], controlPasses: true), racing: true);

    expect([$mutant->status(), [...$mutant->killers()]])->toEqual([MutantStatus::Killed, [freshRaceTest('adds')]])
        ->and($commands)->toHaveCount(4);
});

it('runs the fresh run again in full where the winning part fails unmutated, and judges by that run', function (): void {
    [$mutant, $commands] = freshRaceJudged(freshRaceShell(['run' => 'subtracts', 'adds' => 'adds'], controlPasses: false), racing: true);

    expect([$mutant->status(), [...$mutant->killers()]])->toEqual([MutantStatus::Killed, [freshRaceTest('subtracts')]])
        ->and($commands)->toHaveCount(5);
});

it('judges every mutant as its fresh run alone does, raced or not, where no part kills it', function (string $runFails, string $runEnds, MutantStatus $status): void {
    $failing = $runFails === '' ? [] : ['run' => $runFails];
    [$raced, $racedCommands] = freshRaceJudged(freshRaceShell($failing, controlPasses: true, runEnds: $runEnds), racing: true);
    Scratch::sweep();
    [$alone, $aloneCommands] = freshRaceJudged(freshRaceShell($failing, controlPasses: true, runEnds: $runEnds), racing: false);

    expect($raced->status())->toBe($status)
        ->and($alone->status())->toBe($status)
        ->and([count($racedCommands), count($aloneCommands)])->toBe([3, 1]);
})->with([
    'every test passes' => ['', '', MutantStatus::Survived],
    'only the run kills, by a test no part fails' => ['adds', '', MutantStatus::Killed],
    'the run stops at its limit with the mutant served' => ['', 'stopped', MutantStatus::TimedOut],
    'the run fails on exactly the memory cap' => ['', 'memory', MutantStatus::OutOfMemory],
    'the run ends before it serves the mutant' => ['', 'unserved', MutantStatus::Unjudged],
]);

it('judges killed by a part whose unmutated run passes a mutant its fresh run alone would time out on, both a detection', function (): void {
    $shell = static fn(): ShellFake => freshRaceShell(['adds' => 'adds'], controlPasses: true, runEnds: 'stopped');
    [$raced] = freshRaceJudged($shell(), racing: true);
    Scratch::sweep();
    [$alone] = freshRaceJudged($shell(), racing: false);

    expect([$raced->status(), [...$raced->killers()]])->toEqual([MutantStatus::Killed, [freshRaceTest('adds')]])
        ->and($alone->status())->toBe(MutantStatus::TimedOut);
});
