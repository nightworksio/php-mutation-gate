<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\BaselineCommand;
use NightWorksIO\MutationGate\Cli\Command\FlowOptions;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

afterEach(function (): void {
    Scratch::sweep();
});

/** The command line's composition of a flow in a project. */
$composed = static fn(): Composition => FlowCommands::composition(
    FlowCommands::project(),
    ScriptedRunner::fixture(),
    new ProofStoreFake(),
    Flows::ci(),
);

/** What `run` is handed: `plan`'s options, and `--plan`, `--shard` and `--results`. */
$given = static fn(array $options): InputInterface => new ArrayInput(
    $options,
    RunCommand::command($composed())->getDefinition(),
);

/** What `baseline` is handed, a command with none of the flows' options. */
$bare = static fn(): InputInterface => new ArrayInput([], BaselineCommand::command($composed())->getDefinition());

it('offers plan\'s options: a ref to change since, a full run, a coverage map, a count of shards', function () use (
    $composed,
): void {
    $definition = PlanCommand::command($composed())->getDefinition();

    expect($definition->getOption('changed-since')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('full')->acceptValue())->toBeFalse()
        ->and($definition->getOption('coverage')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('shards')->isValueRequired())->toBeTrue()
        ->and(array_keys($definition->getOptions()))->toBe(['changed-since', 'full', 'coverage', 'shards']);
});

it('runs in full unless asked to consider a change', function (array $options, Mode $mode, bool $full) use (
    $given,
): void {
    expect(FlowOptions::mode($given($options)))->toEqual($mode)
        ->and(FlowOptions::isFull($given($options)))->toBe($full);
})->with([
    'nothing asked' => [[], Mode::full(), true],
    '--full' => [['--full' => true], Mode::full(), true],
    '--changed-since' => [['--changed-since' => 'origin/main'], Mode::since('origin/main'), false],
    'last-passed' => [['--changed-since' => 'last-passed'], Mode::since('last-passed'), false],
]);

it('refuses a full run and a change-scoped one asked for together', function () use ($given): void {
    expect(FlowOptions::mode($given(['--full' => true, '--changed-since' => 'origin/main'])))
        ->toEqual(CannotJudge::because('--full and --changed-since ask for different runs. Give one of them.'));
});

it('runs in full where the command takes none of the flows\' options', function () use ($bare): void {
    expect(FlowOptions::mode($bare()))->toEqual(Mode::full())
        ->and(FlowOptions::isFull($bare()))->toBeTrue();
});

it('runs the whole suite under coverage into the workspace, unless a map is named', function () use ($given): void {
    expect(FlowOptions::coverage($given([])))
        ->toEqual(CoverageRun::of(WholeSuite::tests(), Workspace::coverage()))
        ->and(FlowOptions::coverage($given(['--coverage' => 'build/coverage'])))
        ->toEqual(CoverageRead::from(Path::of('build/coverage')));
});

it('cuts shards by the config\'s size and most, or into as many as asked', function () use ($given): void {
    $settings = Flows::settings(Shards::seconds(600), Shards::max(12));

    expect(FlowOptions::cut($given([]), $settings))->toEqual(Cut::bySize(600, 12))
        ->and(FlowOptions::cut($given(['--shards' => '3']), $settings))->toEqual(Cut::exactly(3))
        ->and(FlowOptions::cut($given(['--shards' => '12']), $settings))->toEqual(Cut::exactly(12));
});

it('cuts shards to the config\'s target wall time, with its setup, unless a count is asked', function () use (
    $given,
): void {
    $settings = Flows::settings(Shards::target('20m'), Shards::setup('90s'), Shards::max(12));

    expect(FlowOptions::cut($given([]), $settings))->toEqual(Cut::toTarget(Seconds::of(1200.0), Seconds::of(90.0), 12))
        ->and(FlowOptions::cut($given(['--shards' => '3']), $settings))->toEqual(Cut::exactly(3));
});

it('refuses a count of shards that is not one or more', function (string $shards) use ($given): void {
    expect(FlowOptions::cut($given(['--shards' => $shards]), Flows::settings()))
        ->toEqual(CannotJudge::because(sprintf('--shards=%s is not a number of shards.', $shards)));
})->with(['0', '03', 'two', '2x', '-1', ' 2']);

it('names the shard --shard gives, and none where it gives none', function () use ($given, $bare): void {
    expect(FlowOptions::shard($given(['--shard' => '2'])))->toEqual(ShardId::of(2))
        ->and(FlowOptions::shard($given(['--shard' => '10'])))->toEqual(ShardId::of(10))
        ->and(FlowOptions::shard($given([])))->toEqual(Absent::setting())
        ->and(FlowOptions::shard($bare()))->toEqual(Absent::setting());
});

it('refuses a shard that is not a shard number', function (string $shard) use ($given): void {
    expect(FlowOptions::shard($given(['--shard' => $shard])))
        ->toEqual(CannotJudge::because(sprintf('--shard=%s is not a shard number.', $shard)));
})->with(['0', 'two', '1 ']);

it('reads the path an option names, or the one given where it names none', function () use ($given, $bare): void {
    expect(FlowOptions::path($given(['--plan' => 'ci/plan.json']), FlowOptions::PLAN, Workspace::plan()))
        ->toEqual(Path::of('ci/plan.json'))
        ->and(FlowOptions::path($given([]), FlowOptions::PLAN, Workspace::plan()))->toEqual(Workspace::plan())
        ->and(FlowOptions::path($bare(), FlowOptions::RESULTS, Workspace::results()))->toEqual(Workspace::results());
});
