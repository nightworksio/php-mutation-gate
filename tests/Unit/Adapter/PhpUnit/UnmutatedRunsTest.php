<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\UnmutatedRuns;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project of one file, Money, whose sum a test covers. */
function unmutatedPhpUnitProject(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

/** The controls of a project, run through a shell. */
function unmutated(Project $project, PhpUnitShellFake $shell): UnmutatedRuns
{
    return new UnmutatedRuns($project, $shell, new TestFiles($project), new CapDirectory());
}

$whole = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());

/** A control of a file by Money's test, allowed this long. */
function phpUnitControl(string $file, float $limit = 5.0): Control
{
    return Control::of(Path::of($file), TestIds::of(TestId::of('Tests\MoneyTest::testAdds')), Seconds::of($limit));
}

/** A PHPUnit whose runs record Money's test as it went, write the guard, and end so. */
function controlPhpUnit(Outcome $outcome, Ran $ran): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($outcome, $ran): Ran {
        $test = 'Tests\MoneyTest::testAdds';
        file_put_contents($command->environment()[Variable::Results->value], sprintf('%s%s', Outcome::Started->line($test), $outcome->line($test)));
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return $ran;
    });
}

/** What the controls found, failing where they cannot run. */
function controlled(UnmutatedRuns $runner, Controls $controls, MutationRequest $request): ControlRuns
{
    $runs = $runner->of($request, $controls);

    return $runs instanceof ControlRuns ? $runs : throw new LogicException($runs->why());
}

it('runs each control as a mutant that changes nothing: its file as the project holds it, served through the override, its tests selected, allowed its limit', function () use ($whole): void {
    $project = unmutatedPhpUnitProject();
    $shell = controlPhpUnit(Outcome::Passed, Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.7)));

    $runs = controlled(unmutated($project, $shell), Controls::of(phpUnitControl('src/Money.php', 6.0)), $whole);
    $command = $shell->commands()[0];
    $mutated = $command->environment()[Variable::Mutated->value];

    expect($runs->of(phpUnitControl('src/Money.php', 6.0)))->toEqual(ControlRun::passed(Seconds::of(0.7)))
        ->and($command->environment()[Variable::Mutant->value])->toBe(realpath(sprintf('%s/src/Money.php', $project->root())))
        ->and(file_get_contents($mutated))->toBe("<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n")
        ->and($command->deadline())->toEqual(Seconds::of(6.0));
});

it('reads how each control ended: passed, failed, or ran out of its limit with its file served', function (Outcome $outcome, Ran $ran, ControlEnd $end) use ($whole): void {
    $runs = controlled(unmutated(unmutatedPhpUnitProject(), controlPhpUnit($outcome, $ran)), Controls::of(phpUnitControl('src/Money.php')), $whole);

    expect($runs->of(phpUnitControl('src/Money.php'))->end())->toBe($end);
})->with([
    'passed' => [Outcome::Passed, Ran::finished(succeeded: true, output: ''), ControlEnd::Passed],
    'failed' => [Outcome::Failed, Ran::finished(succeeded: false, output: ''), ControlEnd::Failed],
    'stopped at its limit' => [Outcome::Started, Ran::stopped(''), ControlEnd::RanOut],
]);

it('runs each control of one file apart, in files of its own, side by side', function () use ($whole): void {
    $project = unmutatedPhpUnitProject();
    $shell = controlPhpUnit(Outcome::Passed, Ran::finished(succeeded: true, output: ''));

    $runs = controlled(unmutated($project, $shell), Controls::of(phpUnitControl('src/Money.php', 5.0), phpUnitControl('src/Money.php', 6.0)), $whole);
    $results = array_map(static fn(Command $command): string => $command->environment()[Variable::Results->value], $shell->commands());

    expect($shell->batches())->toBe([2])
        ->and(array_unique($results))->toHaveCount(2)
        ->and($runs->of(phpUnitControl('src/Money.php', 6.0))->end())->toBe(ControlEnd::Passed);
});

it('never runs a control whose file cannot be read, nor one not started by the request\'s deadline, saying why', function () use ($whole): void {
    $project = unmutatedPhpUnitProject();
    $shell = controlPhpUnit(Outcome::Passed, Ran::finished(succeeded: true, output: ''))->startingAtMost(0);

    $runs = controlled(unmutated($project, $shell), Controls::of(phpUnitControl('src/Gone.php'), phpUnitControl('src/Money.php')), $whole);

    expect($runs->of(phpUnitControl('src/Gone.php'))->why())->toBe(sprintf(Control::UNREAD, 'src/Gone.php'))
        ->and($runs->of(phpUnitControl('src/Money.php'))->why())->toBe(ControlRuns::NOT_RUN)
        ->and($shell->commands())->toBe([]);
});

it('reads a control stopped at its limit before it served the file as having run out', function () use ($whole): void {
    $runs = controlled(unmutated(unmutatedPhpUnitProject(), PhpUnitShellFake::answering(Ran::stopped(''))), Controls::of(phpUnitControl('src/Money.php')), $whole);

    expect($runs->of(phpUnitControl('src/Money.php'))->end())->toBe(ControlEnd::RanOut);
});

it('cannot run controls without an override or a cap to run them with', function (string $broken) use ($whole): void {
    $project = unmutatedPhpUnitProject();
    $request = $broken === 'cap' ? $whole->cappedAt(MemoryCap::standard()) : $whole;

    if ($broken === 'cap') {
        mkdir(sprintf('%s/.mutation-gate/phpunit', $project->root()), recursive: true);
        symlink(Scratch::directory(), sprintf('%s/.mutation-gate/phpunit/php', $project->root()));
    }

    if ($broken === 'override') {
        Scratch::write($project->root(), '.mutation-gate/phpunit/override.php/inside', 'a directory where the script goes');
    }

    set_error_handler(static fn(): bool => true);
    $runs = unmutated($project, controlPhpUnit(Outcome::Passed, Ran::finished(succeeded: true, output: '')))->of($request, Controls::of(phpUnitControl('src/Money.php')));
    restore_error_handler();

    expect($runs)->toBeInstanceOf(CannotJudge::class);
})->with(['override', 'cap']);

it('starts each control through the launcher, and gives it the peak the launcher wrote', function () use ($whole): void {
    $project = unmutatedPhpUnitProject();
    $shell = new PhpUnitShellFake(static function (Command $command): Ran {
        $test = 'Tests\MoneyTest::testAdds';
        file_put_contents($command->environment()[Variable::Results->value], sprintf('%s%s', Outcome::Started->line($test), Outcome::Passed->line($test)));
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");
        file_put_contents($command->arguments()[2], '20480');

        return Ran::finished(succeeded: true, output: '');
    });

    $runs = controlled(unmutated($project, $shell), Controls::of(phpUnitControl('src/Money.php')), $whole);
    $arguments = $shell->commands()[0]->arguments();

    expect(array_slice($arguments, 0, 2))->toBe([PHP_BINARY, PeakLauncher::in($project->own(Control::DIRECTORY))])
        ->and($arguments[3])->toBe(PHP_BINARY)
        ->and($runs->of(phpUnitControl('src/Money.php'))->peak())->toEqual(PeakLauncher::peakIn('20480', PHP_OS_FAMILY));
});

it('gives a control no peak an earlier run left in its place, where the launcher wrote none', function () use ($whole): void {
    $project = unmutatedPhpUnitProject();
    Scratch::write($project->own(Control::DIRECTORY), 'peak-0', '99999');
    $shell = new PhpUnitShellFake(static function (Command $command): Ran {
        $test = 'Tests\MoneyTest::testAdds';
        file_put_contents($command->environment()[Variable::Results->value], sprintf('%s%s', Outcome::Started->line($test), Outcome::Passed->line($test)));
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return Ran::finished(succeeded: true, output: '');
    });

    $runs = controlled(unmutated($project, $shell), Controls::of(phpUnitControl('src/Money.php')), $whole);

    expect($shell->commands()[0]->arguments()[2])->toBe(sprintf('%s/peak-0', $project->own(Control::DIRECTORY)))
        ->and($runs->of(phpUnitControl('src/Money.php'))->peak())->toEqual(NotGiven::value());
});
