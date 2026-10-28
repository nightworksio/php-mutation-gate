<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Adapter\Pest\UnmutatedRuns;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\Control\TimeoutControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Leftover;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\JUnitLogs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding `src/Money.php` and `src/Tax.php`. */
function unmutatedProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfinal class Tax\n{\n}\n");

    return Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** A control of a file by Pest tests of `tests/MoneySpec.php`, allowed this long. */
function pestControl(string $file, float $limit = 5.0, string ...$descriptions): Control
{
    $tests = $descriptions === [] ? ['it_adds'] : $descriptions;

    return Control::of(
        Path::of($file),
        TestIds::of(...array_map(static fn(string $test): TestId => TestId::of(sprintf('P\\Tests\\MoneySpec::__pest_evaluable_%s', $test)), $tests)),
        Seconds::of($limit),
    );
}

/** The request the controls run under, judged by the whole suite and capping nothing. */
function unmutatedRequest(): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
}

/** What the controls found, failing where the runs cannot be readied. */
function unmutatedRuns(Project $at, ShellFake $shell, Controls $controls, MutationRequest $request): ControlRuns
{
    $runs = new UnmutatedRuns($at, $shell, new CapDirectory())->of($request, $controls);

    return $runs instanceof ControlRuns ? $runs : throw new LogicException($runs->why());
}

it('runs each control with its file served unmutated through Pest\'s override, by Pest\'s filter of its tests, allowed its limit', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: '')->took(Seconds::of(1.5)));

    $runs = unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php', 7.0)), unmutatedRequest());
    $command = $shell->commands()[0];
    $environment = $command->environment();
    $copy = $environment[Recorder::MUTATED];

    expect($runs->of(pestControl('src/Money.php', 7.0)))->toEqual(ControlRun::passed(Seconds::of(1.5)))
        ->and($environment[Recorder::MUTANT])->toBe(sprintf('%s/src/Money.php', $at->root()))
        ->and(is_string($copy) ? file_get_contents($copy) : $copy)->toBe("<?php\n\nfinal class Money\n{\n}")
        ->and(implode(' ', $command->arguments()))->toContain('MoneySpec::(.*)it.adds')
        ->and($command->arguments())->toContain(sprintf('--log-junit=%s/pest/controls/junit-0.xml', $at->workspace()))
        ->and($command->deadline())->toEqual(Seconds::of(7.0));
});

it('names the tests a failed control\'s JUnit log records failing', function (): void {
    $shell = new ShellFake(static function (Command $command): Ran {
        JUnitLogs::failing($command->arguments(), 'it adds', 'tests/WalletSpec.php::it adds', 'Tests\\WalletSpec');

        return Ran::finished(succeeded: false, output: 'FAILED');
    });

    $runs = unmutatedRuns(unmutatedProject(), $shell, Controls::of(pestControl('src/Money.php')), unmutatedRequest());

    expect($runs->of(pestControl('src/Money.php')))
        ->toEqual(ControlRun::failed(TestIds::of(TestId::of('P\Tests\WalletSpec::__pest_evaluable_it_adds'))));
});

/** The `--filter` pattern a control's command passes. */
function controlFilter(ShellFake $shell, int $at = 0): string
{
    $arguments = $shell->commands()[$at]->arguments();

    return $arguments[(int) array_search('--filter', $arguments, strict: true) + 1];
}

it('runs exactly the row of a data set a control names, not the data set\'s other rows', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $row = 'it_answers#dataset "Extenders::whatBecameOf"';

    unmutatedRuns(unmutatedProject(), $shell, Controls::of(pestControl('src/Money.php', 5.0, $row)), unmutatedRequest());
    $pattern = controlFilter($shell);
    $named = static fn(string $row): string => sprintf('Tests\MoneySpec::it answers with data set "dataset "%s""', $row);

    expect(preg_match($pattern, $named('Extenders::whatBecameOf')))->toBe(1)
        ->and(preg_match($pattern, $named('Extenders::install')))->toBe(0)
        ->and(preg_match($pattern, $named('Pausers::whatBecameOf')))->toBe(0);
});

it('runs a timeout\'s control of a data set\'s rows as its mutant\'s own run ran them, patched or not', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $rows = array_map(
        static fn(string $row): TestId => TestId::of(sprintf('P\Tests\MoneySpec::__pest_evaluable_it_answers#dataset "%s"', $row)),
        ['Extenders::whatBecameOf', 'Extenders::install', 'Pausers::whatBecameOf'],
    );
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), $rows[0])
        ->covered(Path::of('src/Tax.php'), Line::of(3), $rows[1], $rows[2]);
    $hung = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'LessThan', '-<', 0),
        'hung',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        MutantStatus::TimedOut,
        Unmeasured::duration(),
    )->withLimit(Seconds::of(7.0));
    $controlOf = static fn(Patching $patching): Controls
        => TimeoutControls::of(Mutants::of($hung), UnfilteredRuns::none(), $map, HeldCovered::none(), $patching->selection(new TestFiles(unmutatedProject())))->asked();

    unmutatedRuns(unmutatedProject(), $shell, $controlOf(Patching::on(Group::named('canary'))), unmutatedRequest());
    unmutatedRuns(unmutatedProject(), $shell, $controlOf(Patching::off()), unmutatedRequest());
    $selects = static fn(string $pattern): array => array_map(
        static fn(string $row): int => (int) preg_match($pattern, sprintf('Tests\MoneySpec::it answers with data set "dataset "%s""', $row)),
        ['Extenders::whatBecameOf', 'Extenders::install', 'Pausers::whatBecameOf'],
    );

    expect(controlFilter($shell, 0))->toBe(Selection::covering([$rows[0]->value()], Suite::of(TestIds::of(...$rows)))->filter()->pattern())
        ->and($selects(controlFilter($shell, 0)))->toBe([1, 0, 0])
        ->and($selects(controlFilter($shell, 1)))->toBe($selects(Selection::of(TestIds::of($rows[0]))->filter()->pattern()))
        ->and($selects(controlFilter($shell, 1)))->toBe([1, 1, 1]);
});

it('reads how each control ended: passed in the time it took, failed, or ran out of its limit', function (Ran $ran, ControlRun $found): void {
    $runs = unmutatedRuns(unmutatedProject(), ShellFake::answering($ran), Controls::of(pestControl('src/Money.php')), unmutatedRequest());

    expect($runs->of(pestControl('src/Money.php')))->toEqual($found);
})->with([
    'passed' => [fn(): Ran => Ran::finished(succeeded: true, output: '')->took(Seconds::of(2.0)), fn(): ControlRun => ControlRun::passed(Seconds::of(2.0))],
    'failed' => [fn(): Ran => Ran::finished(succeeded: false, output: 'FAILED'), fn(): ControlRun => ControlRun::failed(TestIds::none())],
    'stopped at its limit' => [fn(): Ran => Ran::stopped(''), fn(): ControlRun => ControlRun::ranOut()],
]);

it('runs a control whose tests Pest\'s filter cannot hold by the tests that judge the request', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $many = array_map(static fn(int $at): string => sprintf('it_takes_case_%d_with_a_long_description_to_pass_the_ceiling', $at), range(1, 2000));

    unmutatedRuns(
        $at,
        $shell,
        Controls::of(pestControl('src/Money.php', 5.0, ...$many)),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php')),
    );

    expect($shell->commands()[0]->arguments())->toContain('holds:src/Money.php');
});

it('runs a control as its mutant\'s run selected its tests: by its filter, by the files it loaded with no filter, or by every file', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $tests = TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds'));
    $running = static fn(RunTests $run): Controls => Controls::of(Control::running(Path::of('src/Money.php'), $run, Seconds::of(5.0)));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds'));

    unmutatedRuns(unmutatedProject(), $shell, $running(RunTests::filtered($tests, Filter::matching('"MoneySpec::"'))), $request);
    unmutatedRuns(unmutatedProject(), $shell, $running(RunTests::loading($tests, Paths::of(Path::of('tests/MoneySpec.php')))), $request);
    unmutatedRuns(unmutatedProject(), $shell, $running(RunTests::loading($tests, WholeSuite::tests())), $request);
    [$filtered, $files, $every] = array_map(static fn(Command $command): array => $command->arguments(), $shell->commands());

    expect($filtered)->toContain('"MoneySpec::"')
        ->and($files)->toContain('tests/MoneySpec.php')
        ->and($files)->toContain('holds')
        ->and(implode(' ', $files))->not->toContain('--filter')
        ->and($every)->toContain('holds')
        ->and(implode(' ', $every))->not->toContain('--filter')
        ->and(implode(' ', $every))->not->toContain('tests/MoneySpec.php');
});

it('runs each control over the judging suites, and the holding suites too where Pest\'s filter names its tests', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $suites = Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process')));
    $request = unmutatedRequest()->narrowedTo(unmutatedRequest()->files(), $suites)
        ->reusingCoverage(Handed::maps(Path::of('shard'), Path::of('whole')));
    $many = array_map(static fn(int $at): string => sprintf('it_takes_case_%d_with_a_long_description_to_pass_the_ceiling', $at), range(1, 2000));

    unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php')), $request);
    unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php', 5.0, ...$many)), $request);

    expect($shell->commands()[0]->arguments())->toContain('--testsuite=Unit,Process')
        ->and($shell->commands()[1]->arguments())->toContain('--testsuite=Unit');
});

it('never runs a control whose file cannot be read, saying why, and runs the rest', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = unmutatedRuns($at, $shell, Controls::of(pestControl('src/Gone.php'), pestControl('src/Tax.php')), unmutatedRequest());
    $gone = $runs->of(pestControl('src/Gone.php'));

    expect($gone->end())->toBe(ControlEnd::Unrun)
        ->and($gone->why())->toBe(sprintf(Control::UNREAD, 'src/Gone.php'))
        ->and($runs->of(pestControl('src/Tax.php'))->end())->toBe(ControlEnd::Passed)
        ->and($shell->commands())->toHaveCount(1);
});

it('runs the controls side by side in the request\'s pool, none started once its deadline has passed', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = unmutatedRuns(
        $at,
        $shell,
        Controls::of(pestControl('src/Money.php'), pestControl('src/Tax.php')),
        unmutatedRequest()->within(Seconds::of(0.0)),
    );

    expect($shell->batches())->toBe([2])
        ->and($shell->commands())->toBe([])
        ->and($runs->of(pestControl('src/Money.php'))->why())->toBe(ControlRuns::NOT_RUN);
});

it('caps each control\'s process at the request\'s memory cap, as a mutant\'s own run is, and removes the cap once they ran', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php')), unmutatedRequest()->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes)));

    expect($shell->commands()[0]->environment())->toHaveKey(MemoryCap::SCAN_DIR)
        ->and(is_dir(MemoryScan::directoryBeside(sprintf('%s/.mutation-gate/pest/controls/runs', $at->root()))))->toBeFalse();
});

it('cannot run the controls where an earlier run left a file in their place that cannot be removed', function (): void {
    $at = unmutatedProject();
    $place = sprintf('%s/.mutation-gate/pest/controls/runs', $at->root());
    mkdir($place, recursive: true);

    $runs = new UnmutatedRuns($at, ShellFake::answering(Ran::finished(succeeded: true, output: '')), new CapDirectory())
        ->of(unmutatedRequest(), Controls::of(pestControl('src/Money.php')));

    expect($runs)->toBeInstanceOf(CannotJudge::class);
});

it('cannot run the controls where their memory cap cannot be written', function (): void {
    $at = unmutatedProject();
    $beside = sprintf('%s/.mutation-gate/pest/controls/runs', $at->root());
    mkdir(sprintf('%s/%s', MemoryScan::directoryBeside($beside), MemoryCap::FILE), recursive: true);

    $runs = new UnmutatedRuns($at, ShellFake::answering(Ran::finished(succeeded: true, output: '')), new CapDirectory())
        ->of(unmutatedRequest()->cappedAt(MemoryCap::standard()), Controls::of(pestControl('src/Money.php')));

    expect($runs)->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', MemoryScan::directoryBeside($beside), MemoryCap::FILE))));
});

it('starts each control through the launcher, and gives it the peak the launcher wrote', function (): void {
    $at = unmutatedProject();
    $shell = new ShellFake(static function (Command $command): Ran {
        file_put_contents($command->arguments()[2], '20480');

        return Ran::finished(succeeded: true, output: '')->took(Seconds::of(1.0));
    });

    $runs = unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php')), unmutatedRequest());
    $arguments = $shell->commands()[0]->arguments();

    expect(array_slice($arguments, 0, 2))->toBe([PHP_BINARY, PeakLauncher::in(sprintf('%s/.mutation-gate/pest/controls', $at->root()))])
        ->and(file_get_contents($arguments[1]))->toBe(PeakLauncher::SCRIPT)
        ->and($arguments[3])->toBe(PHP_BINARY)
        ->and($runs->of(pestControl('src/Money.php'))->peak())->toEqual(PeakLauncher::peakIn('20480', PHP_OS_FAMILY));
});

it('runs no control where something it cannot clear stands where the launcher goes, saying what', function (): void {
    $at = unmutatedProject();
    $launcher = sprintf('%s/.mutation-gate/%s', $at->root(), PeakLauncher::in('pest/controls'));
    mkdir($launcher, recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = new UnmutatedRuns($at, $shell, new CapDirectory())->of(unmutatedRequest(), Controls::of(pestControl('src/Money.php')));

    expect($runs)->toEqual(Leftover::at($launcher))
        ->and($shell->commands())->toBe([]);
});

it('writes the launcher each control starts through', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php')), unmutatedRequest());

    expect(file_get_contents(sprintf('%s/.mutation-gate/%s', $at->root(), PeakLauncher::in('pest/controls'))))
        ->toBe(PeakLauncher::SCRIPT);
});

it('runs no control where the launcher cannot be written, saying where', function (): void {
    $at = unmutatedProject();
    $controls = sprintf('%s/.mutation-gate/pest/controls', $at->root());
    mkdir($controls, recursive: true);
    chmod($controls, 0o555);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    set_error_handler(static fn(): bool => true);

    try {
        $runs = new UnmutatedRuns($at, $shell, new CapDirectory())->of(unmutatedRequest(), Controls::of(pestControl('src/Money.php')));
    } finally {
        restore_error_handler();
        chmod($controls, 0o755);
    }

    expect($runs)->toEqual(PeakLauncher::unwritten(PeakLauncher::in($controls)))
        ->and($shell->commands())->toBe([]);
});
