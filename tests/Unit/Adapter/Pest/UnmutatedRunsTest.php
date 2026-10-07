<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\UnmutatedRuns;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
        ->and($command->deadline())->toEqual(Seconds::of(7.0));
});

it('reads how each control ended: passed in the time it took, failed, or ran out of its limit', function (Ran $ran, ControlRun $found): void {
    $runs = unmutatedRuns(unmutatedProject(), ShellFake::answering($ran), Controls::of(pestControl('src/Money.php')), unmutatedRequest());

    expect($runs->of(pestControl('src/Money.php')))->toEqual($found);
})->with([
    'passed' => [Ran::finished(succeeded: true, output: '')->took(Seconds::of(2.0)), ControlRun::passed(Seconds::of(2.0))],
    'failed' => [Ran::finished(succeeded: false, output: 'FAILED'), ControlRun::failed()],
    'stopped at its limit' => [Ran::stopped(''), ControlRun::ranOut()],
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

    expect($shell->commands()[0]->arguments())->toContain('--group=holds:src/Money.php');
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

it('caps each control\'s process at the request\'s memory cap, as a mutant\'s own run is', function (): void {
    $at = unmutatedProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    unmutatedRuns($at, $shell, Controls::of(pestControl('src/Money.php')), unmutatedRequest()->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes)));

    expect($shell->commands()[0]->environment())->toHaveKey(MemoryCap::SCAN_DIR);
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
