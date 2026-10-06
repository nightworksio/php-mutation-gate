<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\Clock;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus as AcmePlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Unexecutables;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

const RUN_PLUS = PlusToMinus::class;

const RUN_ADDS = 'P\Tests\MoneySpec::__pest_evaluable_it_adds';

const RUN_LISTING = "   INFO  Available test groups:\n\n - default (2 tests)\n - mutation-canary (1 test).\n";

/**
 * A coverage map in a project's root, covering these lines of its files, by the one test RUN_ADDS.
 *
 * @param array<non-empty-string, array<positive-int, list<int<0, max>>>> $lines
 * @param array<non-empty-string, float>                                $durations
 */
function adapterMap(string $root, string $file, array $lines, array $durations): void
{
    CoverageMaps::write(sprintf('%s/%s', $root, $file), sprintf('%s/', $root), $lines, [RUN_ADDS], $durations);
}

/** A request to mutate src/Money.php against the whole suite. */
function adapterMoney(): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
}

/** The results file every run of a project records to. */
function adapterResults(Project $at): string
{
    return sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
}

/** Pest patched with the canary group mutation-canary. */
function adapterCanary(): Patching
{
    return Patching::on(Group::named('mutation-canary'));
}

/** Pest's command lines in a project that installs its packages in `vendor`. */
function adapterInvocation(): Invocation
{
    return Invocation::installedIn(Path::of('vendor'));
}

/** A project in a new directory, by its real path, that installs its packages in `vendor` or another directory. */
function adapterProject(string $vendor = 'vendor'): Project
{
    $root = (string) realpath(Scratch::directory());

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of($vendor));
}

/** What Pest and the plugin leave when a run mutates src/Money.php's line 11 once, and a test it names kills it. */
function adapterKilled(Command $command, Project $project): Ran
{
    $results = sprintf('%s', $command->environment()['MUTATION_GATE_RESULTS'] ?? '');

    if (is_file($results)) {
        return Ran::finished(succeeded: false, output: 'an earlier run\'s results were left in place');
    }

    $money = sprintf('%s/src/Money.php', $project->root());
    $map = Recorder::coverageBeside($results);
    CoverageMaps::write($map, sprintf('%s/', $project->root()), ['src/Money.php' => [11 => [0]]], [RUN_ADDS], []);
    PestRun::write($results, [
        PestRun::planned('n1', $money, 11, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
        PestRun::made(1),
        PestRun::killed('n1', RUN_ADDS),
        PestRun::finished('n1', PestStatus::Tested, 0.25),
        PestRun::end(),
    ]);

    return Ran::finished(succeeded: true, output: '  Mutations: 1 tested');
}

/** The mutant that run reports. */
function adapterMutant(): Mutant
{
    $diff = Diff::fromPest("\n  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n");
    $path = Path::of('src/Money.php');

    return Mutant::of(
        MutantId::hash($path, RUN_PLUS, $diff, 0),
        'n1',
        Location::of($path, Line::of(11), Line::of(11)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Killed,
        Seconds::of(0.25),
    )->killedBy(TestIds::of(TestId::of(RUN_ADDS)));
}

/** A project with a patched copy of the installed pest-plugin-mutate, and the planning job's map. */
function adapterPatched(string $vendor = 'vendor'): Project
{
    $at = adapterProject($vendor);

    MutatePlugin::pristine()->into(sprintf('%s/%s', $at->root(), $vendor));
    Patch::applyIn(sprintf('%s/%s', $at->root(), $vendor));
    adapterHandedOver($at, 'planned', CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 11, RUN_ADDS))
        ->timedEach(TimedTest::of(RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0)));

    return $at;
}

/** The gate's own map, as another job hands it over in a directory of the project. */
function adapterHandedOver(Project $at, string $directory, CoverageMap $map): void
{
    Scratch::write($at->root(), CoverageMapFile::in(Path::of($directory))->value(), CoverageMapFile::encode($map, Unplaced::map()));
}

it('names Pest, the exact versions it mutates with, and the PHP it runs on', function (): void {
    $at = adapterProject();
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => '1.0.0', 'dist' => ['reference' => 'abc']],
        $installed,
    );
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));

    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: Described::output()));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $withheld = Withheld::of('DEPLOY_*');

    expect($pest->identity($withheld))->toEqual(Identity::of(
        'pest',
        Versions::of(...array_map(static fn(string $name): Version => Version::of($name, '1.0.0', 'abc'), $installed)),
        Described::platform()->digest(),
    ))
        ->and($pest->identity($withheld))->toEqual($pest->identity($withheld))
        ->and($shell->commands())->toEqual([Command::php($withheld, ...Platform::describing())]);
});

it('cannot say which Pest it runs where the PHP it starts does not describe itself', function (): void {
    $at = adapterProject();
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => '1.0.0'],
        ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'],
    );
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'Segmentation fault'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->identity(Withheld::standard()))->toEqual(CannotJudge::because(
        'The PHP the runner starts could not describe itself, so no proof can be keyed: '
        . "it printed no description of itself:\nSegmentation fault",
    ));
});

it('cannot say which Pest it runs where Composer installed none', function (): void {
    $at = adapterProject();
    $shell = ShellFake::answering(Ran::stopped(''));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->identity(Withheld::standard()))->toEqual(CannotJudge::because(sprintf(
        '%s/vendor/composer/installed.json does not list pestphp/pest, pestphp/pest-plugin-mutate, phpunit/phpunit, '
        . 'phpunit/php-code-coverage, so the gate cannot say which Pest judges the mutants. Run composer install.',
        $at->root(),
    )));
});

it('lists the suite\'s groups as Pest lists them', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));

    $groups = new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->groups(Withheld::of('CI_JOB_TOKEN'));

    expect($groups)->toEqual(Groups::of(Group::named('mutation-canary')))
        ->and($shell->commands())->toEqual([adapterInvocation()->listingGroups(Withheld::of('CI_JOB_TOKEN'))]);
});

it('runs the suite under coverage into a directory it makes, with pcov collecting from the whole project, and reads the map', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static function () use ($at): Ran {
        $lines = ['src/Money.php' => [11 => [0]]];
        adapterMap($at->root(), '.mutation-gate/coverage/coverage.php', $lines, [RUN_ADDS => 0.5]);

        return Ran::finished(succeeded: true, output: 'OK');
    });
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(RUN_ADDS))
        ->timed(TestId::of(RUN_ADDS), Seconds::of(0.5)))
        ->and($shell->commands())
        ->toEqual([adapterInvocation()->coverage($request, $directory, PcovReach::under($at->root(), Path::of('vendor')))]);
});

it('cannot judge a coverage run that failed, with what Pest said', function (): void {
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'No code coverage driver'));

    expect(new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))
        ->toEqual(CannotJudge::because("Pest's coverage run failed. Pest said:\nNo code coverage driver"));
});

it('times a run of no test, started as a mutant\'s own run of a file whose mutant is an unchanged copy', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', '<?php // money');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: 'No tests found.')->took(Seconds::of(1.8)));
    $copy = sprintf('%s/.mutation-gate/pest/start-up/Money.php', $at->root());

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->startUp(Path::of('src/Money.php'), Withheld::of('DEPLOY_*')))
        ->toEqual(Seconds::of(1.8))
        ->and($shell->commands())->toEqual([
            adapterInvocation()->startingUp(Withheld::of('DEPLOY_*'), sprintf('%s/src/Money.php', $at->root()), $copy),
        ])
        ->and(file_get_contents($copy))->toBe('<?php // money');
});

it('cannot judge a run of no test that failed, with what Pest said, or one of a file that is not there', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', '<?php');
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error')->took(Seconds::of(0.4)));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because("Pest's run of no test, timing a mutant's start-up, failed. Pest said:\nFatal error"))
        ->and($pest->startUp(Path::of('src/Gone.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because("Pest's run of no test needs an unchanged copy of src/Gone.php, and it could not be made."));
});

it('cannot time a run of no test where an earlier copy cannot be removed', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', '<?php');
    $copy = sprintf('%s/.mutation-gate/pest/start-up/Money.php', $at->root());
    mkdir($copy, recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('An earlier run left %s, and the gate cannot remove it.', $copy)))
        ->and($shell->commands())->toBe([]);
});

it('reads the gate\'s own map another job handed over, running nothing', function (): void {
    $at = adapterProject();
    $map = CoverageMap::empty()->covered(Path::of('src/Held.php'), Line::of(5), TestId::of(RUN_ADDS));
    adapterHandedOver($at, 'planned', $map);
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'not run'));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->coverage(CoverageRead::from(Path::of('planned'))))->toEqual($map)
        ->and($shell->commands())->toBe([]);
});

it('never reads a runner\'s map another job wrote, which is PHP that reading runs', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'planned/coverage.php', '<?php throw new RuntimeException(\'ran\');');
    $pest = new Pest($at, ShellFake::answering(Ran::finished(succeeded: false, output: '')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->coverage(CoverageRead::from(Path::of('planned'))))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/planned/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('names the tests of a map that some test files hold, by the class Pest declares for each', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'tests/MoneySpec.php', '<?php');
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(RUN_ADDS))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('P\Tests\HeldSpec::__pest_evaluable_it_holds'));
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->testsIn(Paths::of(Path::of('tests/MoneySpec.php')), $map))->toEqual(TestIds::of(TestId::of(RUN_ADDS)));
});

it('names the test files a covering test\'s filter selects, or all when it will not fit', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'tests/MoneySpec.php', '<?php');
    Scratch::write($at->root(), 'tests/HeldSpec.php', '<?php');
    $long = sprintf('P\Tests\HeldSpec::__pest_evaluable_%s', str_repeat('x', Ceiling::BYTES));
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(RUN_ADDS))
        ->covered(Path::of('src/Kernel.php'), Line::of(3), TestId::of($long));
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $every = Paths::of(Path::of('tests/HeldSpec.php'), Path::of('tests/MoneySpec.php'));

    expect($pest->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneySpec.php')))
        ->and($pest->judges(Path::of('src/Kernel.php'), $map))->toEqual($every)
        ->and($pest->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('refuses to judge by a filter, which Pest cannot select held tests by', function (): void {
    $shell = ShellFake::answering(Ran::stopped(''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Kernel.php')), Filter::matching('KernelTest'));

    expect(new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(
        'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter KernelTest.',
    ))->and($shell->commands())->toBe([]);
});

it('mutates with a fresh results file, and reads what the plugin recorded', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), '.mutation-gate/pest/results.jsonl', 'an earlier run');
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect($result)->toEqual(MutationResult::of(Mutants::of(adapterMutant()), 0))
        ->and($shell->commands())
        ->toEqual([adapterInvocation()->mutation(adapterMoney(), WholeSuite::tests(), adapterResults($at))]);
});

it('keeps every PHP process of a capped run to the cap, through an ini file beside the results it removes once done', function (): void {
    $at = adapterProject();
    $read = [];
    $shell = new ShellFake(static function (Command $command) use ($at, &$read): Ran {
        $read[] = (string) file_get_contents(sprintf('%s/%s', MemoryScan::directoryBeside(adapterResults($at)), MemoryCap::FILE));

        return adapterKilled($command, $at);
    });
    $capped = adapterMoney()->cappedAt(MemoryCap::standard());
    $directory = MemoryScan::directoryBeside(adapterResults($at));

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($capped);

    expect($result)->toEqual(MutationResult::of(Mutants::of(adapterMutant()), 0))
        ->and($shell->commands())->toEqual([
            adapterInvocation()->mutation($capped, WholeSuite::tests(), adapterResults($at))->with([
                MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory),
            ]),
        ])
        ->and($read)->toBe(["memory_limit=1G\n"])
        ->and(is_dir($directory))->toBeFalse();
});

it('cannot judge a capped run whose cap cannot be written', function (): void {
    $at = adapterProject();
    mkdir(sprintf('%s/%s', MemoryScan::directoryBeside(adapterResults($at)), MemoryCap::FILE), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney()->cappedAt(MemoryCap::standard())))
        ->toEqual(CannotJudge::because(sprintf(
            MemoryCap::UNWRITTEN,
            sprintf('%s/%s', MemoryScan::directoryBeside(adapterResults($at)), MemoryCap::FILE),
        )))
        ->and($shell->commands())->toBe([]);
});

it('puts the likely killers first where the request asks, handing the plugin the history in a fresh order directory', function (): void {
    $at = adapterProject();
    $order = sprintf('%s/.mutation-gate/order', $at->root());
    Scratch::write($at->root(), '.mutation-gate/order/m1/test-run-history', 'an earlier run');
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $history = KillHistory::none()->withFunction(
        Enclosing::named(Path::of('src/Money.php'), 'add'),
        Ranking::of(Kills::of(TestId::of(RUN_ADDS), 1)),
    );
    $request = adapterMoney()->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toEqual([
        adapterInvocation()->mutation($request, WholeSuite::tests(), adapterResults($at))->with([GateVariable::Order->value => $order]),
    ])->and(Plan::read($order))->toEqual($history)
        ->and(is_file(sprintf('%s/m1/test-run-history', $order)))->toBeFalse();
});

it('cannot judge where an earlier run\'s orders cannot be removed', function (): void {
    $at = adapterProject();
    mkdir(sprintf('%s/.mutation-gate/order/plan.json', $at->root()), recursive: true);
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $request = adapterMoney()->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, KillHistory::none()), MatrixKind::FirstKiller));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left orders in %s/.mutation-gate/order, and the gate cannot remove them.',
        $at->root(),
    )))->and($shell->commands())->toBe([]);
});

it('mutates against a group without reading a shared map', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toEqual([
        adapterInvocation()->mutation($request, $held, adapterResults($at))->with(['MUTATION_GATE_NARROW' => '1', 'MUTATION_GATE_MUTANT_FLOOR' => '10.000000', 'MUTATION_GATE_MUTANT_CAP' => '300.000000']),
    ]);
});

it('opens a patched shard on the canary group, with the planning job\'s map written again for its Pest', function (): void {
    $at = adapterPatched();
    $written = sprintf('%s/shared.coverage.php', dirname(adapterResults($at)));
    $loaded = null;
    $shell = new ShellFake(static function (Command $command, int $before) use ($at, $written, &$loaded): Ran {
        $loaded ??= is_file($written) ? CoverageFile::at($written) : null;

        return $before === 0 ? Ran::finished(succeeded: true, output: RUN_LISTING) : adapterKilled($command, $at);
    });
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($loaded instanceof CoverageFile ? $loaded->map($at) : $loaded)->toEqual(
            CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 11, RUN_ADDS))
                ->timedEach(TimedTest::of(RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0)),
        )
        ->and($shell->commands())->toEqual([
            adapterInvocation()->listingGroups(Withheld::standard()),
            adapterInvocation()->mutation($request, WholeSuite::tests(), adapterResults($at))->with([
                'MUTATION_GATE_SHARED_COVERAGE' => $written,
                'MUTATION_GATE_SUITE_SECONDS' => '3.250000',
                'MUTATION_GATE_CANARY' => 'mutation-canary',
                'MUTATION_GATE_NARROW' => '1',
                'MUTATION_GATE_MUTANT_FLOOR' => '10.000000',
                'MUTATION_GATE_MUTANT_CAP' => '300.000000',
            ]),
        ]);
});

it('runs a patched shard\'s canary group again alone where its opening run failed no test and still failed', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: RUN_LISTING)
        : Ran::exited(1, "  Tests:    1 passed (1 assertions)\n"));
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($result)->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toHaveCount(3)
        ->and($shell->commands()[2])->toEqual(
            adapterInvocation()
                ->opening($request, Group::named('mutation-canary'), sprintf('%s.events', adapterResults($at)))
                ->within(Unlimited::time()),
        );
});

it('runs the held tests again alone where a patched run against them failed none and still failed', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(): Ran => Ran::exited(1, "  Tests:    1 passed (1 assertions)\n"));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toHaveCount(2)
        ->and($shell->commands()[1])->toEqual(
            adapterInvocation()->opening($request, $held, sprintf('%s.events', adapterResults($at)))->within(Unlimited::time()),
        );
});

it('judges a patched shard\'s mutant on a line that is not executable by the tests the plan\'s whole map says read its value, within the cap where the map timed none of them', function (): void {
    $at = Unexecutables::project();
    MutatePlugin::pristine()->into(sprintf('%s/vendor', $at->root()));
    Patch::applyIn(sprintf('%s/vendor', $at->root()));
    $other = CoveredLine::of(Path::of('src/Money.php'), 14, 'P\\Tests\\OtherSpec::__pest_evaluable_it_runs_the_other');
    $internal = CoveredLine::of(Path::of('src/Money.php'), 10, 'P\\Tests\\InternalSpec::__pest_evaluable_it_runs');
    adapterHandedOver($at, 'own', CoverageMap::of($other));
    adapterHandedOver($at, 'whole', CoverageMap::of($other, $internal));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => match (true) {
        $before === 0 => Ran::finished(succeeded: true, output: RUN_LISTING),
        in_array('--mutate', $command->arguments(), strict: true) => Ran::finished(
            succeeded: Unexecutables::run($at, ['internal']) !== '',
            output: '  Mutations: 1 uncovered',
        ),
        default => Unexecutables::answering($command, ['tests/InternalSpec.php']),
    });
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), LimitBounds::between(Seconds::of(7.0), Seconds::of(7.0)))->mutate($request);
    $trials = array_slice($shell->commands(), 2);

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Killed])
        ->and(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $trials))
        ->toEqual([Seconds::of(7.0), Seconds::of(7.0)]);
});

it('cannot judge a run whose plugin wrote no results, and judges no mutant left uncovered', function (): void {
    $at = Unexecutables::project();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: '  Mutations: 1 uncovered'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))
        ->toEqual(CannotJudge::because(sprintf(
            'Pest wrote no results to %s/.mutation-gate/pest/results.jsonl. Is pestphp/pest-plugin allowed to run in composer.json?',
            $at->root(),
        )))
        ->and($shell->commands())->toHaveCount(1);
});

it('cannot judge a patched shard\'s run without the plan\'s whole map', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: RUN_LISTING)
        : adapterKilled($command, $at));
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('whole')));

    expect(new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CoverageMapFile::missingAt($at->absolute(CoverageMapFile::in(Path::of('whole')))));
});

it('opens a shard on its own suite unpatched, or when it collects its own map', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $reusing = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($reusing);
    new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect($shell->commands())->toEqual([
        adapterInvocation()->mutation($reusing, WholeSuite::tests(), adapterResults($at)),
        adapterInvocation()->mutation(adapterMoney(), WholeSuite::tests(), adapterResults($at))
            ->with(['MUTATION_GATE_NARROW' => '1', 'MUTATION_GATE_MUTANT_FLOOR' => '10.000000', 'MUTATION_GATE_MUTANT_CAP' => '300.000000']),
    ]);
});

it('cannot open a shard on the canary group without the patch applied', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    expect(new Pest(adapterProject(), $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(
        'pest.patch is on, but pest-plugin-mutate in vendor is not patched. Run mutation-gate pest:patch.',
    ))->and($shell->commands())->toBe([]);
});

it('finds Pest, what Composer installed and the patch in the vendor directory the project installs into', function (): void {
    $at = adapterPatched('lib/vendor');
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(static fn(string $name): array => ['name' => $name, 'version' => '1.0.0'], $installed);
    Scratch::write($at->root(), 'lib/vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => match ($before) {
        0 => Ran::finished(succeeded: true, output: RUN_LISTING),
        1 => Ran::finished(succeeded: true, output: Described::output()),
        default => adapterKilled($command, $at),
    });
    $pest = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds());
    $invocation = Invocation::installedIn(Path::of('lib/vendor'));

    expect($pest->groups(Withheld::standard()))->toBeInstanceOf(Groups::class)
        ->and($pest->identity(Withheld::standard()))->toBeInstanceOf(Identity::class)
        ->and($pest->mutate(adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')))))->toBeInstanceOf(MutationResult::class)
        ->and($shell->commands()[0])->toEqual($invocation->listingGroups(Withheld::standard()));
});

it('cannot open a shard on a canary group with no test, or one it cannot list', function (): void {
    $at = adapterPatched();
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $empty = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));
    $unlisted = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));
    $other = Patching::on(Group::named('canary'));

    expect(new Pest($at, $empty, $other, new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CannotJudge::because('pest.patch is on, but the canary group canary holds no test. Add one.'))
        ->and(new Pest($at, $unlisted, $other, new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CannotJudge::because(
            "Pest did not list the suite's groups, so no group can hold a path. Pest said:\nbroken",
        ));
});

it('cannot open a shard on the canary group without the planning job\'s map', function (): void {
    $at = adapterPatched();
    $request = adapterMoney()->reusingCoverage(Handed::maps(Path::of('absent'), Path::of('absent')));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));

    expect(new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/absent/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('runs the mutants again in one run of their files with their mutators, naming each to a patched plugin, matched back by id', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $survivor = adapterMutant();
    $place = Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12));
    $change = Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, '-gone');
    $id = MutantId::hash(Path::of('src/Money.php'), RUN_PLUS, '-gone', 0);
    $gone = Mutant::of($id, 'n9', $place, $change, MutantStatus::Survived, Unmeasured::duration());
    $elsewhere = Mutant::of(
        MutantId::hash(Path::of('src/Held.php'), RUN_PLUS, '-held', 0),
        'n8',
        Location::of(Path::of('src/Held.php'), Line::of(3), Line::of(3)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, '-held'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $invocation = MutationRequest::of(Paths::of(Path::of('src'), Path::of('lib')), WholeSuite::tests())
        ->leavingOut(Paths::of(Path::of('src/Held')))
        ->within(Seconds::of(42.0));
    $request = $invocation->narrowedTo(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')), Narrowing::none()->toMutators(Mutators::named(RUN_PLUS)));
    $retried = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->retry($invocation, Mutants::of($survivor, $gone, $elsewhere), Seconds::of(20.0));
    $notFound = Reason::that('Run again alone, Pest made no mutant with this id.');

    expect($retried)->toEqual(Mutants::of(
        $survivor,
        Mutant::of($id, 'n9', $place, $change, MutantStatus::Unjudged, Unmeasured::duration())->because($notFound),
        Interpretation::unjudged($elsewhere, $notFound),
    ))->and($shell->commands())->toEqual([
        adapterInvocation()->mutation($request, WholeSuite::tests(), adapterResults($at))->with(['MUTATION_GATE_ONLY' => sprintf('%s.only', adapterResults($at))]),
    ])
        ->and(file_get_contents(sprintf('%s.only', adapterResults($at))))->toBe("n1\nn9\nn8")
        ->and(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::none(), Seconds::of(20.0)))
        ->toEqual(Mutants::none());
});

it('runs mutants again on the canary group, reading the map the planning job handed the invocation, under the raised limit', function (): void {
    $at = adapterPatched();
    $written = sprintf('%s/shared.coverage.php', dirname(adapterResults($at)));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: RUN_LISTING)
        : adapterKilled($command, $at));
    $invocation = adapterMoney()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $retried = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::of(adapterMutant()), Seconds::of(20.0));

    expect($retried)->toEqual(Mutants::of(adapterMutant()))
        ->and($shell->commands()[1] ?? null)->toEqual(adapterInvocation()->mutation(
            $invocation->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(RUN_PLUS))),
            WholeSuite::tests(),
            adapterResults($at),
        )->with([
            'MUTATION_GATE_SHARED_COVERAGE' => $written,
            'MUTATION_GATE_SUITE_SECONDS' => '3.250000',
            'MUTATION_GATE_CANARY' => 'mutation-canary',
            'MUTATION_GATE_ONLY' => sprintf('%s.only', adapterResults($at)),
            'MUTATION_GATE_NARROW' => '1',
            'MUTATION_GATE_MUTANT_FLOOR' => '10.000000',
            'MUTATION_GATE_MUTANT_CAP' => '20.000000',
        ]));
});

it('runs a held unit\'s mutant again by the group that holds it, withholding what it is told to', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $holding = Group::named('holds:src/Money.php');
    $invocation = MutationRequest::of(Paths::of(Path::of('src')), $holding)->withholding(Withheld::of('DEPLOY_*'));
    $request = $invocation->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(RUN_PLUS)));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::of(adapterMutant()), Seconds::of(20.0));

    expect($shell->commands())->toEqual([
        adapterInvocation()->mutation($request, $holding, adapterResults($at))->with(['MUTATION_GATE_ONLY' => sprintf('%s.only', adapterResults($at))]),
    ]);
});

it('hands each mutant run again its own result, though the mutants run again are numbered apart from the rest', function (): void {
    $at = adapterProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = new ShellFake(static function (Command $command) use ($money): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', dirname($money, 2)), ['src/Money.php' => [20 => [0], 30 => [0]]], [RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('pB', $money, 20, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::planned('pC', $money, 30, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(2),
            PestRun::killed('pB', RUN_ADDS),
            PestRun::finished('pB', PestStatus::Tested, 0.25),
            PestRun::finished('pC', PestStatus::Untested, 0.5),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: '  Mutations: 1 untested, 1 tested');
    });
    $diff = Diff::fromPest(PestRun::diff('return $a + $b;', 'return $a - $b;'));
    // The same change on three lines: the first run numbers them 0, 1 and 2.
    $survivor = static fn(string $native, int $line, int $occurrence): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), RUN_PLUS, $diff, $occurrence),
        $native,
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $b = $survivor('pB', 20, 1);
    $c = $survivor('pC', 30, 2);

    $retried = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry(adapterMoney(), Mutants::of($b, $c), Seconds::of(20.0));
    $by = static fn(Mutants|CannotJudge $mutants): array => $mutants instanceof Mutants ? array_map(
        static fn(Mutant $mutant): array => [$mutant->id()->value(), $mutant->nativeId(), $mutant->status()],
        [...$mutants],
    ) : [];

    expect($by($retried))->toBe([
        [$b->id()->value(), 'pB', MutantStatus::Killed],
        [$c->id()->value(), 'pC', MutantStatus::Survived],
    ]);
});

it('hands each of the mutants that share Pest\'s id the one found again on its own line, and none found on no line of its own', function (int $one, int $two): void {
    $at = adapterProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = new ShellFake(static function (Command $command) use ($money, $one, $two): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', dirname($money, 2)), ['src/Money.php' => [max(1, $one) => [0], max(1, $two) => [0]]], [RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('pD', $money, $one, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::planned('pD', $money, $two, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(2),
            PestRun::finished('pD', PestStatus::Tested, 0.25),
            PestRun::finished('pD', PestStatus::Untested, 0.5),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: '  Mutations: 1 untested, 1 tested');
    });
    $diff = Diff::fromPest(PestRun::diff('return $a + $b;', 'return $a - $b;'));
    $survivor = static fn(int $line, int $occurrence): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), RUN_PLUS, $diff, $occurrence),
        'pD',
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $retried = static fn(Mutant ...$asked): array => array_map(
        static fn(Mutant $mutant): array => [$mutant->id()->value(), $mutant->location()->start()->number(), $mutant->status()],
        [...(($again = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry(adapterMoney(), Mutants::of(...$asked), Seconds::of(20.0))) instanceof Mutants ? $again : Mutants::none())],
    );
    $first = $survivor($one, 0);
    $second = $survivor($two, 1);

    expect($retried($first, $second))->toBe([
        [$first->id()->value(), $one, MutantStatus::Killed],
        [$second->id()->value(), $two, MutantStatus::Survived],
    ])->and($retried($second))->toBe([[$second->id()->value(), $two, $one === $two ? MutantStatus::Killed : MutantStatus::Survived]])
        ->and($retried($survivor(60, 2), $first))->toBe([
            [$survivor(60, 2)->id()->value(), 60, MutantStatus::Unjudged],
            [$first->id()->value(), $one, MutantStatus::Killed],
        ]);
})->with([
    'on two lines' => [35, 40],
    'on one line' => [50, 50],
]);

/**
 * A shell whose first mutation run kills src/Money.php's line 11 with no test named as its killer, as a run that
 * could not load its tests does, and whose next one, loading every test file, finds it survives.
 */
function adapterLoadedNothing(Project $at, string ...$killers): ShellFake
{
    return new ShellFake(static function (Command $command, int $before) use ($at, $killers): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        $money = sprintf('%s/src/Money.php', $at->root());
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [RUN_ADDS], []);
        $status = $before === 0 ? PestStatus::Tested : PestStatus::Untested;
        PestRun::write($results, [
            PestRun::planned('n1', $money, 11, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(1),
            ...($before === 0 ? array_values($killers) : []),
            PestRun::finished('n1', $status, 0.25),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: sprintf('  Mutations: 1 %s', $status->value));
    });
}

it('runs a mutant a narrowed run killed with no killer again with every test file, before it counts', function (MutationRequest $request): void {
    $at = adapterProject();
    $shell = adapterLoadedNothing($at);

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);
    $narrow = array_map(
        static fn(Command $command): string|false|null => $command->environment()[GateVariable::Narrow->value] ?? null,
        $shell->commands(),
    );

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Survived])
        ->and($narrow)->toBe(['1', false])
        ->and(file_get_contents(sprintf('%s.only', adapterResults($at))))->toBe('n1');
})->with([
    'within a deadline' => [adapterMoney()->within(Seconds::of(60.0))],
    'with no deadline' => [adapterMoney()],
]);

it('runs a mutant a narrowed run killed only by tests that errored again with every test file, before it counts', function (): void {
    $at = adapterProject();
    $shell = adapterLoadedNothing($at, PestRun::errored('n1', RUN_ADDS), PestRun::errored('n1', 'T::subtracts'));

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(2);
});

it('counts a mutant a narrowed run killed where a test failed, whatever else errored', function (): void {
    $at = adapterProject();
    $shell = adapterLoadedNothing($at, PestRun::errored('n1', 'T::subtracts'), PestRun::killed('n1', RUN_ADDS));

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Killed])
        ->and($shell->commands())->toHaveCount(1);
});

/**
 * A patched run of src/Money.php in which a test kills the mutant of line 11 in its own run narrowed to
 * tests/MoneySpec.php, which it survives with every test file, and the tests of the narrowed files, alone on the
 * unmutated code, pass or fail.
 */
function adapterNarrowedKill(Project $at, bool $passAlone): ShellFake
{
    return new ShellFake(static function (Command $command) use ($at, $passAlone): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');

        if ($results === '') {
            return Ran::finished(succeeded: $passAlone, output: 'the narrowed files alone');
        }

        $narrowed = ($command->environment()[GateVariable::Narrow->value] ?? false) === '1';
        $status = $narrowed ? PestStatus::Tested : PestStatus::Untested;
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('n1', sprintf('%s/src/Money.php', $at->root()), 11, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(1),
            ...($narrowed ? [PestRun::killed('n1', RUN_ADDS), PestRun::narrowed('n1', [adapterSpec($at)])] : []),
            PestRun::finished('n1', $status, 0.25),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: sprintf('  Mutations: 1 %s', $status->value));
    });
}

/** The test file a narrowed run of the project loads. */
function adapterSpec(Project $at): string
{
    return sprintf('%s/tests/MoneySpec.php', $at->root());
}

/**
 * The status of each mutant a result holds, or why there is none.
 *
 * @return list<MutantStatus>|CannotJudge
 */
function adapterStatuses(MutationResult|CannotJudge $result): array|CannotJudge
{
    return $result instanceof MutationResult
        ? array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$result->mutants()])
        : $result;
}

it('counts a narrowed kill whose files\' tests pass alone on the unmutated code, running them once however many runs loaded them', function (): void {
    $at = adapterProject();
    $shell = adapterNarrowedKill($at, passAlone: true);
    $pest = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds());

    $first = $pest->mutate(adapterMoney());
    $again = $pest->mutate(adapterMoney());
    $baselines = array_values(array_filter(
        $shell->commands(),
        static fn(Command $command): bool => ($command->environment()[GateVariable::Results->value] ?? false) === false,
    ));

    expect([adapterStatuses($first), adapterStatuses($again)])->toBe([[MutantStatus::Killed], [MutantStatus::Killed]])
        ->and($shell->commands())->toHaveCount(3)
        ->and($baselines)->toHaveCount(1)
        ->and(array_slice($baselines[0]->arguments(), -1))->toBe([adapterSpec($at)])
        ->and($baselines[0]->arguments())->toContain('--no-tia');
});

it('runs a narrowed kill whose files\' tests fail alone on the unmutated code again with every test file, before it counts', function (): void {
    $at = adapterProject();
    $shell = adapterNarrowedKill($at, passAlone: false);

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney()->within(Seconds::of(60.0)));

    expect(adapterStatuses($result))->toBe([MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(3);
});

/**
 * A patched run of src/Money.php in which a test kills the mutants of lines 11 and 16, each in its own run
 * narrowed to a file of its own, and both survive with every test file; the tests of the narrowed files, alone on
 * the unmutated code, pass for these files only.
 */
function adapterNarrowedKills(Project $at, string ...$passAlone): ShellFake
{
    return new ShellFake(static function (Command $command) use ($at, $passAlone): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        $arguments = $command->arguments();

        if ($results === '') {
            return Ran::finished(succeeded: in_array(end($arguments), $passAlone, strict: true), output: 'the narrowed files alone');
        }

        $narrowed = ($command->environment()[GateVariable::Narrow->value] ?? false) === '1';
        $status = $narrowed ? PestStatus::Tested : PestStatus::Untested;
        $money = sprintf('%s/src/Money.php', $at->root());
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0], 16 => [0]]], [RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('n1', $money, 11, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::planned('n2', $money, 16, RUN_PLUS, 'return $a + $c;', 'return $a - $c;'),
            PestRun::made(2),
            ...($narrowed ? [
                PestRun::killed('n1', RUN_ADDS),
                PestRun::narrowed('n1', [adapterSpec($at)]),
                PestRun::killed('n2', RUN_ADDS),
                PestRun::narrowed('n2', [sprintf('%s/tests/OtherSpec.php', $at->root())]),
            ] : []),
            PestRun::finished('n1', $status, 0.25),
            PestRun::finished('n2', $status, 0.25),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: sprintf('  Mutations: 2 %s', $status->value));
    });
}

it('runs again only the narrowed kill whose own files\' tests fail alone, each set of files run on its own', function (): void {
    $at = adapterProject();
    $shell = adapterNarrowedKills($at, adapterSpec($at));

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect(adapterStatuses($result))->toBe([MutantStatus::Killed, MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(4)
        ->and(file_get_contents(sprintf('%s.only', adapterResults($at))))->toBe('n2');
});

it('bounds the run of a narrowed kill\'s files alone, and its run again, by the time left of the deadline', function (): void {
    $at = adapterProject();
    $shell = adapterNarrowedKill($at, passAlone: false);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(adapterMoney()->within(Seconds::of(60.0)));

    expect(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(60.0), Seconds::of(50.0), Seconds::of(40.0)]);
});

it('leaves a narrowed kill unjudged where no time is left to run its files\' tests alone', function (): void {
    $at = adapterProject();
    $shell = adapterNarrowedKill($at, passAlone: true);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(adapterMoney()->within(Seconds::of(10.0)));

    expect(adapterStatuses($result))->toBe([MutantStatus::Unjudged])
        ->and($shell->commands())->toHaveCount(1);
});

it('cannot judge a narrowed run whose run again with every test file failed', function (): void {
    $at = adapterProject();
    $loaded = adapterLoadedNothing($at);
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? $loaded->run($command)
        : Ran::finished(succeeded: false, output: 'broken'));

    expect(new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney()))
        ->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('leaves a mutant a narrowed run killed with no killer unjudged where no time is left to run it again', function (): void {
    $at = adapterProject();
    $shell = adapterLoadedNothing($at);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    $result = new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(adapterMoney()->within(Seconds::of(5.0)));
    $mutants = $result instanceof MutationResult ? [...$result->mutants()] : [];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::Unjudged])
        ->and(array_map(static fn(Mutant $mutant): object => $mutant->reason(), $mutants))->toEqual([Reason::that(
            "Killed where its covering tests' files alone cannot vouch for the kill; no time is left to run them all.",
        )])
        ->and($shell->commands())->toHaveCount(1);
});

it('runs no mutant again that an unnarrowed run killed with no killer', function (): void {
    $at = adapterProject();
    $shell = adapterLoadedNothing($at);

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney());

    expect($shell->commands())->toHaveCount(1);
});

it('cannot judge a retry whose run failed', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));

    $retried = new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->retry(adapterMoney(), Mutants::of(adapterMutant()), Seconds::of(20.0));

    expect($retried)->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('reproduces a mutant in one run of its file with only its mutator, by the tests given, with what Pest printed', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $holding = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $holding)
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(RUN_PLUS)))
        ->withholding(Withheld::of('DEPLOY_*'));

    $reproduced = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of(adapterMutant()), MutationRequest::of(Paths::none(), $holding)->withholding(Withheld::of('DEPLOY_*')), Seconds::of(20.0));

    expect($reproduced instanceof Reproduction ? [$reproduced->mutant(), $reproduced->printed()] : $reproduced)->toEqual([adapterMutant(), '  Mutations: 1 tested'])
        ->and($shell->commands())->toEqual([adapterInvocation()->mutation($request, $holding, adapterResults($at))]);
});

it('reproduces a mutant under the cap its request carries, as the run it came from', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests())->cappedAt(MemoryCap::of(256, MemoryUnit::Megabytes));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->reproduce(Reproducible::of(adapterMutant()), $request, Seconds::of(20.0));

    expect(array_map(static fn(Command $command): mixed => $command->environment()[MemoryCap::SCAN_DIR] ?? null, $shell->commands()))
        ->toBe([MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), MemoryScan::directoryBeside(adapterResults($at)))]);
});

it('reproduces a mutant patched Pest allows no more than the most it is given, above the floor', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests());

    new Pest($at, $shell, adapterCanary(), new CapDirectory(), Triage::standard()->bounds())->reproduce(Reproducible::of(adapterMutant()), $request, Seconds::of(20.0));

    expect(array_map(static fn(Command $command): array => [
        $command->environment()['MUTATION_GATE_MUTANT_FLOOR'] ?? null,
        $command->environment()['MUTATION_GATE_MUTANT_CAP'] ?? null,
    ], $shell->commands()))->toBe([['10.000000', '20.000000']]);
});

it('says Pest made no mutant with the id where the run no longer makes it', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $place = Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12));
    $change = Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, '-gone');
    $gone = Mutant::of(MutantId::hash(Path::of('src/Money.php'), RUN_PLUS, '-gone', 0), 'n9', $place, $change, MutantStatus::Survived, Unmeasured::duration());

    $reproduced = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of($gone), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(20.0));

    expect($reproduced instanceof Reproduction ? $reproduced->mutant() : $reproduced)
        ->toEqual(Unmade::because(Reason::that('Run again alone, Pest made no mutant with this id.')));
});

it('cannot judge a reproduction whose run failed', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));

    $reproduced = new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of(adapterMutant()), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(20.0));

    expect($reproduced)->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('mutates nothing, and runs nothing, where no file is asked for', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests());

    expect(new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('mutates nothing, and runs nothing, where Pest runs none of the mutators a request names', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(AcmePlusToMinus::class)));
    $request = adapterMoney()->narrowedTo(adapterMoney()->files(), Narrowing::none()->toMutators(Mutators::named('default/UnwrapHtmlspecialchars')));

    expect(new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: $bridges)->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('refuses a path with a comma, which Pest\'s lists of paths split on', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $pest = new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $asked = MutationRequest::of(Paths::of(Path::of('src/a,b.php')), WholeSuite::tests());
    $leftOut = adapterMoney()->leavingOut(Paths::of(Path::of('src/c,d.php'), Path::of('src/e.php')));

    expect($pest->mutate($asked))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/a,b.php less .",
    ))->and($pest->mutate($leftOut))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/Money.php less src/c,d.php, src/e.php.",
    ))->and($shell->commands())->toBe([]);
});

it('cannot judge a run whose earlier results cannot be removed', function (): void {
    $at = adapterProject();
    mkdir(adapterResults($at), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(adapterMoney()))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s or the map beside it, and the gate cannot remove them.',
        adapterResults($at),
    )))->and($shell->commands())->toBe([]);
});

it('removes an earlier coverage run\'s map and log before it measures again', function (): void {
    $at = adapterProject();
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());
    Scratch::write($at->root(), '.mutation-gate/coverage/coverage.php', '<?php return [];');
    Scratch::write($at->root(), '.mutation-gate/coverage/junit.xml', '<testsuites/>');
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $seen = [];
    $shell = new ShellFake(static function () use ($directory, &$seen): Ran {
        $seen = [is_file(sprintf('%s/coverage.php', $directory)), is_file(sprintf('%s/junit.xml', $directory))];

        return Ran::finished(succeeded: true, output: '');
    });

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CannotJudge::because(
        sprintf('There is no coverage map at %s/coverage.php, so no test runs any line.', $directory),
    ))->and($seen)->toBe([false, false]);
});

it('cannot measure coverage where an earlier map cannot be removed', function (): void {
    $at = adapterProject();
    mkdir(sprintf('%s/.mutation-gate/coverage/coverage.php', $at->root()), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s/.mutation-gate/coverage/coverage.php or its JUnit log, '
        . 'and the gate cannot remove them.',
        $at->root(),
    )))->and($shell->commands())->toBe([]);
});

it('finds every @pest-mutate-ignore in the files asked for, running nothing', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', "<?php\n\n// @pest-mutate-ignore\n");
    $shell = ShellFake::answering(Ran::stopped(''));
    $markers = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->markers(Paths::of(Path::of('src')));

    expect(array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)))
        ->toBe(['src/Money.php:3'])
        ->and($shell->commands())->toBe([]);
});

/** The file a project's listing run names its tests in. */
function adapterNames(Project $at): string
{
    return sprintf('%s/.mutation-gate/pest/names.json', $at->root());
}

/** A listing run in which the plugin names a Pest test, rows and all, and a PHPUnit test of the same suite. */
function adapterNamed(Command $command, Project $at): Ran
{
    $names = sprintf('%s', $command->environment()[GateVariable::Names->value] ?? '');
    file_put_contents($names, (string) json_encode([
        ['test' => 'P\\Tests\\MoneySpec::__pest_evaluable_it_adds', 'file' => sprintf('%s/tests/MoneySpec.php', $at->root()), 'description' => 'it adds'],
        ['test' => 'LegacySpec::decrements', 'file' => sprintf('%s/tests/LegacySpec.php', $at->root()), 'description' => 'decrements'],
        ['file' => 'tests/Broken.php'],
        'not a test',
    ]));

    return Ran::finished(succeeded: true, output: '   INFO  Available tests:');
}

it('names each test as the plugin names it in a run that lists the tests, withholding what it is told to', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterNamed($command, $at));
    $asked = TestIds::of(
        TestId::of(RUN_ADDS),
        TestId::of('P\\Tests\\MoneySpec::__pest_evaluable_it_adds#dataset "one"'),
        TestId::of('LegacySpec::decrements#3'),
        TestId::of('P\\Tests\\GoneSpec::__pest_evaluable_it_goes'),
    );
    $names = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::of('CI_JOB_TOKEN'));
    $adds = TestName::in(Path::of('tests/MoneySpec.php'), 'it adds');

    expect($names)->toEqual(TestNames::none()
        ->with(TestId::of(RUN_ADDS), $adds)
        ->with(TestId::of('P\\Tests\\MoneySpec::__pest_evaluable_it_adds#dataset "one"'), TestRow::of($adds, '"dataset "one""'))
        ->with(TestId::of('LegacySpec::decrements#3'), TestRow::of(TestName::in(Path::of('tests/LegacySpec.php'), 'decrements'), '#3')))
        ->and($shell->commands())->toEqual([adapterInvocation()->listingTests(Withheld::of('CI_JOB_TOKEN'), adapterNames($at))]);
});

it('cannot name the tests where the listing run fails, names nothing, or cannot start afresh', function (): void {
    $at = adapterProject();
    $failed = ShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error'));
    $silent = ShellFake::answering(Ran::finished(succeeded: true, output: '   INFO  Available tests:'));
    $asked = TestIds::of(TestId::of(RUN_ADDS));
    $unnamed = "Pest did not name the suite's tests. Pest said:\n%s";

    expect(new Pest($at, $failed, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf($unnamed, 'Fatal error')))
        ->and(new Pest($at, $silent, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf($unnamed, '   INFO  Available tests:')));

    mkdir(adapterNames($at), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))->toEqual(CannotJudge::because(
        sprintf('An earlier run left %s, and the gate cannot remove it.', adapterNames($at)),
    ))->and($shell->commands())->toBe([]);
});

it('roots itself in a package that installs Pest, running there with the package\'s own files', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'packages/billing/vendor/pestphp/pest/bin/pest', '<?php');
    $shell = new ShellFake(static fn(Command $command): Ran => adapterNamed($command, $at));
    $rooted = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->rootedAt(Path::of('packages/billing'));
    $package = sprintf('%s/packages/billing', $at->root());
    $names = $rooted instanceof Pest ? $rooted->names(TestIds::of(), Withheld::standard()) : $rooted;

    expect($names)->toEqual(TestNames::none())
        ->and($shell->directories())->toBe([$package])
        ->and($shell->commands()[0]->environment()[GateVariable::Names->value] ?? '')
        ->toBe(sprintf('%s/.mutation-gate/pest/names.json', $package));
});

it('roots itself in a package with the cap it was given', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'packages/billing/vendor/pestphp/pest/bin/pest', '<?php');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $package = Path::of('packages/billing');

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), LimitBounds::between(Seconds::of(30.0), Seconds::of(30.0)))->rootedAt($package))
        ->toEqual(new Pest($at->in($package), $shell->in($at->in($package)->root()), Patching::off(), new CapDirectory(), LimitBounds::between(Seconds::of(30.0), Seconds::of(30.0))));
});

it('cannot root itself in a directory that installs no Pest', function (): void {
    $at = adapterProject('lib/vendor');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->rootedAt(Path::of('packages/billing')))->toEqual(CannotJudge::because(
        'packages/billing holds no project Pest can run: Pest is not installed in its lib/vendor.',
    ))->and($shell->directories())->toBe([]);
});

it('is Pest in the project the gate runs in, as its options say, or the options\' problem', function (): void {
    $pest = static fn(LimitBounds $bounds): Pest => new Pest(
        Project::at('.', Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('lib/vendor')),
        new ProcessShell(new LocalProcesses(new SystemClock()), Project::at('.', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'))->root()),
        Patching::off(),
        new CapDirectory(),
        $bounds,
    );

    expect(Pest::fromOptions(Options::none(), Path::of('lib/vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual($pest(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))))
        ->and(Pest::fromOptions(Configs::options('{"timeout": 30, "most": 60}'), Path::of('lib/vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual($pest(LimitBounds::between(Seconds::of(30.0), Seconds::of(60.0))))
        ->and(Pest::fromOptions(Configs::options('{"patch": 1}'), Path::of('vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual(Invalid::because(
            Problem::at('patch', 'expected true or false, got 1'),
        ));
});

it('is defined by tests/Pest.php and the PHPUnit config in the project\'s root, by any of its names', function (): void {
    $definitions = new Pest(adapterProject(), ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds())->definitions();

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['tests/Pest.php', 'phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']);
});

it('reads holds as it loads them, and patched, raises a limit and has every key read its canary; unpatched, raises none', function (): void {
    $canary = Group::named('mutation-canary');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $patched = new Pest(adapterProject(), $shell, Patching::on($canary), new CapDirectory(), Triage::standard()->bounds());
    $unpatched = new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $pest = RunnerBehaviour::standard()
        ->holdingAsLoaded()
        ->runningPerCore()
        ->writingTestsIn(AssertionStyle::Pest);

    expect($patched->behaviour())->toEqual($pest->readingInEveryKey($canary))
        ->and($unpatched->behaviour())->toEqual($pest->raisingNoLimit()->openingEachShard());
});

it('gives a mutant as an analyser checks it: its diff put onto the file as Pest prints it', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $diff = "@@ @@\n-    return 1 + 1;\n+    return 1 - 1;";
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $checkable = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds())->checkable($mutant);

    expect($checkable instanceof Checkable ? $checkable->mutant()->text() : '')
        ->toBe("<?php\n\nfunction add()\n{\n    return 1 - 1;\n}");
});

it('makes the registered mutators\' mutants through the bridges it writes for its plugin, naming each for Pest', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(AcmePlusToMinus::class)));
    $file = sprintf('%s/.mutation-gate/mutators/pest/bridges.php', $at->root());

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: $bridges)->mutate(adapterMoney());

    expect($result)->toEqual(MutationResult::of(Mutants::of(adapterMutant()), 0))
        ->and($shell->commands())->toEqual([
            adapterInvocation()->mutation(adapterMoney(), WholeSuite::tests(), adapterResults($at), $bridges)
                ->with(['MUTATION_GATE_MUTATORS' => $file]),
        ])
        ->and(is_file($file) ? (string) file_get_contents($file) : '')->toContain("return 'acme/PlusToMinus';");
});

it('cannot judge a run whose options name a class that is not a mutator, and starts no Pest', function (): void {
    $shell = new ShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: ''));
    $why = CannotJudge::because('The pest runner cannot make mutants with stdClass, which is not a mutator.');

    expect(new Pest(adapterProject(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: Bridges::refusing($why))->mutate(adapterMoney()))
        ->toBe($why)
        ->and($shell->commands())->toBe([]);
});
