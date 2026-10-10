<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\PrePushCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function (): void {
    Scratch::sweep();
});

/** The one tree, `src`, held to this floor; the fixture scores 40 over it. */
$floored = static fn(float $floor): Trees => Trees::of(
    Tree::at(Path::of('src'), Floor::of($floor), Package::at(Path::root())),
);

/** How the command line composes a flow outside CI, in a project over `src` held to this floor. */
$composed = static fn(string $project, float $floor = 40.0): Composition => FlowCommands::over(
    $floored($floor),
    $project,
    ScriptedRunner::fixture(),
    new ProofStoreFake(),
    Flows::ci(),
    Variables::of([]),
);

/** `pre-push` over this composition, started in an environment that sets no variable the command reads. */
function prePushIn(Composition $composition): Command
{
    return PrePushCommand::command($composition, Variables::of([]));
}

/** `pre-push` as the command line holds it, beside the global `--budget`. */
function prePushBesideBudget(Composition $composition): Command
{
    $command = prePushIn($composition);
    $application = new Application();
    $application->getDefinition()->addOption(new InputOption('budget', mode: InputOption::VALUE_REQUIRED));
    $application->addCommand($command);

    return $command;
}

/** The line git hands the hook for a push of `main` at the checkout's commit, over what the remote holds there. */
$pushed = static fn(string $remote = Flows::REMOTE): string => sprintf(
    'refs/heads/main %s refs/heads/main %s',
    Flows::HEAD,
    $remote,
);

const MORE_TIME_SINCE = "More time judges what the budget left: vendor/bin/mutation-gate run --changed-since=%s\n";

it('judges the push since what the remote holds, prints the score change first, and exits as the verdict does', function (
    float $floor,
    int $code,
    string $standing,
    string $judgement,
) use ($composed, $pushed): void {
    $ran = FlowCommands::handed(prePushBesideBudget($composed(FlowCommands::project(), $floor)), $pushed(), 'remote=origin url=git@example.com:app.git');

    expect($ran->code)->toBe($code)
        ->and($ran->errors)->toBe('')
        ->and($ran->output)->toStartWith(sprintf(
            "src scores 40.00%%%s. That is ±0.00 against the base.\nWrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: %s\n",
            $standing,
            $judgement,
        ))
        ->and($ran->output)->not->toContain('More time judges what the budget left');
})->with([
    'below its floor' => [50.0, 1, ', below its floor of 50.00%', 'failed'],
    'at its floor' => [40.0, 0, ' against its floor of 40.00%', 'passed'],
]);

it('runs under local.prePushBudget, over the config\'s budget, and says what judges what the time left', function (
    string $config,
    string $options,
    string $remote,
    string $since,
) use ($composed, $pushed): void {
    $ran = FlowCommands::handed(prePushBesideBudget($composed(FlowCommands::project($config))), $pushed($remote), $options);

    expect($ran->code)->toBe($since === '' ? 0 : 1)
        ->and($ran->output)->toContain($since === '' ? 'mutation-gate: passed' : 'is unjudged')
        ->and(str_ends_with($ran->output, sprintf(MORE_TIME_SINCE, $since)))->toBe($since !== '');
})->with([
    'the config\'s budget, which pre-push does not take' => ['"budget": "1s"', '', Flows::REMOTE, ''],
    'too little for the push' => ['"local": {"prePushBudget": "1s"}', '', Flows::REMOTE, Flows::REMOTE],
    'too little for a new branch' => ['"local": {"prePushBudget": "1s"}', '', fn(): string => str_repeat('0', 40), Flows::MAIN],
    'too little, as --budget sets it' => ['', '--budget=1s', Flows::REMOTE, Flows::REMOTE],
    'enough, as --budget sets it' => ['"local": {"prePushBudget": "1s"}', '--budget=5m', Flows::REMOTE, ''],
]);

it('prints neither the score change nor what judges the rest as problems for an editor', function () use (
    $composed,
    $pushed,
): void {
    $ran = FlowCommands::handed(
        prePushIn($composed(FlowCommands::project('"local": {"prePushBudget": "1s"}'))),
        $pushed(),
        '--output=problems',
    );

    expect($ran->code)->toBe(1)
        ->and($ran->output)->toStartWith(sprintf("%s\n", Problems::JUDGING))
        ->and($ran->output)->not->toContain('More time judges what the budget left')
        ->and($ran->output)->not->toContain('not measured yet');
});

it('holds the new code to its floor, as a pull request is held, where a run since the same base is not', function () use (
    $floored,
    $pushed,
): void {
    $project = FlowCommands::project();
    $changed = new ChangeSourceFake(
        Revision::ref(Flows::REMOTE),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(16), Line::of(21)))),
        [Revision::workingTree()->name() => Flows::FILES, Flows::REMOTE => Flows::FILES, Flows::MAIN => Flows::FILES],
    );
    $composition = FlowCommands::reading(
        $floored(40.0),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
        RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
        $changed,
    );

    $pushedRun = FlowCommands::handed(prePushIn($composition), $pushed());
    $run = FlowCommands::run(RunCommand::command($composition), sprintf('--changed-since=%s', Flows::REMOTE));

    expect($pushedRun->code)->toBe(1)
        ->and($pushedRun->output)->toContain("New code\n")
        ->and($run->code)->toBe(0)
        ->and($run->output)->not->toContain("New code\n");
});

it('judges once for each base the pushed refs are read since', function () use ($composed, $pushed): void {
    $input = implode("\n", [$pushed(), $pushed(), $pushed(str_repeat('0', 40))]);

    $ran = FlowCommands::handed(prePushIn($composed(FlowCommands::project(), 50.0)), $input);

    expect($ran->code)->toBe(1)
        ->and(substr_count($ran->output, "mutation-gate: failed\n"))->toBe(2);
});

it('judges nothing where nothing is pushed, or the push only deletes, and says so on the console alone', function (
    string $input,
) use ($composed): void {
    $command = prePushIn($composed(FlowCommands::project()));

    $console = FlowCommands::handed($command, $input);
    $problems = FlowCommands::handed($command, $input, '--output=problems');

    expect([$console->code, $console->output, $console->errors])
        ->toBe([0, "Nothing is pushed, so there is nothing to judge.\n", ''])
        ->and([$problems->code, $problems->output])->toBe([0, '']);
})->with([
    'nothing' => [''],
    'a deletion' => [fn(): string => sprintf('(delete) %s refs/heads/gone %s', str_repeat('0', 40), Flows::REMOTE)],
]);

it('cannot judge a push it cannot read, a commit other than the checkout\'s, or a checkout git cannot place', function (
    string $input,
    string $why,
    bool $headless,
) use ($floored): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::checkedOut(
        $floored(40.0),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
        $headless
            ? new RepositoryFake(CannotTell::because('git is gone.'), Scope::branch('main'), Scope::branch('main'))
            : RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
    );

    $ran = FlowCommands::handed(prePushIn($composition), $input);

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toContain($why);
})->with([
    'an unreadable line' => ['refs/heads/main', 'The pre-push hook was handed a line that is not a local ref, its commit, a remote ref and its commit: "refs/heads/main".', false],
    'a commit not named in hex' => [fn(): string => sprintf('refs/heads/main %s refs/heads/main base', Flows::HEAD), 'its commit: "refs/heads/main', false],
    'another commit' => [fn(): string => sprintf('refs/heads/other %s refs/heads/other %s', str_repeat('4', 40), Flows::REMOTE), fn(): string => sprintf('refs/heads/other is pushed at %s', str_repeat('4', 40)), false],
    'no head' => [fn(): string => sprintf('refs/heads/main %s refs/heads/main %s', Flows::HEAD, Flows::REMOTE), 'git is gone.', true],
]);

it('cannot judge with an output it cannot print, a config it cannot read, or a run it cannot plan', function (
    string $config,
    string $options,
    bool $blocked,
) use ($composed, $pushed): void {
    $project = FlowCommands::project($config);

    if ($blocked) {
        mkdir(sprintf('%s/.mutation-gate/results/1.json', $project), recursive: true);
    }

    $ran = FlowCommands::handed(prePushIn($composed($project)), $pushed(), $options);

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->not->toBe('');
})->with([
    'an output' => ['', '--output=nowhere', false],
    'a config' => ['"floors": 5', '', false],
    'a run' => ['', '', true],
]);

it('cannot judge a push whose run cannot be planned, and prints no score change', function () use ($pushed): void {
    $composition = FlowCommands::over(
        Trees::of(Tree::at(Path::of('src'), Floor::of(40), Package::at(Path::of('src')))),
        FlowCommands::project(),
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
    );

    $ran = FlowCommands::handed(prePushIn($composition), $pushed());

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toContain('The package at src has units to mutate');
});

it('takes git\'s remote and URL, and the options that print for an editor', function () use ($composed): void {
    $definition = prePushIn($composed(FlowCommands::project()))->getDefinition();

    expect($definition->getArgument('remote')->isRequired())->toBeFalse()
        ->and($definition->getArgument('url')->isRequired())->toBeFalse()
        ->and($definition->hasOption('output'))->toBeTrue()
        ->and($definition->hasOption('only'))->toBeTrue()
        ->and($definition->hasOption('changed-since'))->toBeFalse()
        ->and($definition->getOption('stdin')->isValueRequired())->toBeTrue();
});

/**
 * `pre-push` run with these options, git's lines on standard input, and its exit code and output.
 *
 * @param array<string, string> $options
 * @return array{int, string}
 */
function prePushHanded(Command $command, string $stdin, array $options = []): array
{
    $tester = new CommandTester($command);
    $tester->setInputs($stdin === '' ? [] : [$stdin]);
    $code = $tester->execute($options, ['capture_stderr_separately' => true]);

    return [$code, Printed::by($tester->getOutput())];
}

it('judges the lines a hook manager hands on through --stdin, and reads no standard input then', function (
    string $stdin,
) use ($composed, $pushed): void {
    $command = PrePushCommand::command(
        $composed(FlowCommands::project(), 50.0),
        Variables::of(['PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/main']),
    );

    [$code, $output] = prePushHanded($command, $stdin, ['--stdin' => sprintf("%s\n", $pushed())]);
    [$none, $said] = prePushHanded($command, $stdin, ['--stdin' => '']);

    expect($code)->toBe(1)
        ->and($output)->toContain('src scores 40.00%, below its floor of 50.00%')
        ->and([$none, $said])->toBe([0, "Nothing is pushed, so there is nothing to judge.\n"]);
})->with([
    'nothing on standard input' => [''],
    'a line it cannot read there' => ['not a line'],
]);

it('judges the push the pre-commit framework names where standard input holds no line', function (
    Variables $set,
    string $stdin,
    string $since,
) use ($composed): void {
    $command = PrePushCommand::command($composed(FlowCommands::project('"local": {"prePushBudget": "1s"}')), $set);

    [$code, $output] = prePushHanded($command, $stdin);

    expect($code)->toBe(1)
        ->and($output)->toEndWith(sprintf(MORE_TIME_SINCE, $since));
})->with([
    'the commits it names' => [
        fn(): Variables => Variables::of([
            'PRE_COMMIT_FROM_REF' => Flows::REMOTE,
            'PRE_COMMIT_TO_REF' => Flows::HEAD,
            'PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/main',
        ]),
        '',
        Flows::REMOTE,
    ],
    'a history the remote holds none of' => [
        fn(): Variables => Variables::of(['PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/main', 'PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/main']),
        "\n",
        Flows::MAIN,
    ],
    'git\'s lines over the variables' => [
        fn(): Variables => Variables::of(['PRE_COMMIT_FROM_REF' => 'not a commit', 'PRE_COMMIT_TO_REF' => Flows::HEAD]),
        fn(): string => sprintf('refs/heads/main %s refs/heads/main %s', Flows::HEAD, Flows::REMOTE),
        Flows::REMOTE,
    ],
]);

it('cannot judge a push the pre-commit framework names by anything but commits', function () use ($composed): void {
    $command = PrePushCommand::command(
        $composed(FlowCommands::project()),
        Variables::of(['PRE_COMMIT_FROM_REF' => 'origin/main', 'PRE_COMMIT_TO_REF' => Flows::HEAD]),
    );

    [$code, $output] = prePushHanded($command, '');

    expect([$code, $output])->toBe([2, '']);
});
