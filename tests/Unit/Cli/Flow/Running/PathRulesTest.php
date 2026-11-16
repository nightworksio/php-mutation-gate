<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Ruling;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\ShardLeft;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Mutators;
use NightWorksIO\MutationGate\Config\PathRule;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** One shard of the money file, the held path and a file of its own. */
$shard = static fn(): Shard => Shard::of(
    ShardId::of(1),
    Package::at(Path::root()),
    Units::of(Planned::money(), Planned::held(), Unit::file(Path::of('src/Extra.php'))),
    Seconds::of(3.0),
    'everything',
);

/** The files each request a runner was handed holds. */
$files = static fn(ScriptedRunner $runner): array => array_values(array_unique(array_merge(...array_map(
    static fn(MutationRequest $request): array => array_map(static fn(Path $path): string => $path->value(), [...$request->files()]),
    $runner->requests(),
))));

it('runs each group of a shard\'s units paths entries match with the runner its settings build, the rest as they are', function () use ($shard, $files): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Extra.php', "<?php\n\nfinal class Extra\n{\n}\n");
    $global = ScriptedRunner::fixture();
    $ruled = [];
    $ruling = Ruling::by(static function (Settings $settings, Directory $directory) use (&$ruled, $project): Adapters {
        $runner = ScriptedRunner::fixture();
        $ruled[] = [$settings, $runner, $directory->root()->value()];

        return Flows::adapters($project, [], $runner);
    });
    $settings = Flows::settings(
        PathRule::matching('src/Money.php', Gate::configure()->with(Timeouts::seconds(60), Mutators::except('default/Plus'))),
        PathRule::matching('src/Extra.php', Gate::configure()->with(Timeouts::seconds(90))),
    );
    $plan = Planned::handedIn($project, Planned::of($shard()));
    $written = new Running(Flows::adapters($project, [], $global), $settings, Flows::setup(), ruling: $ruling)
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = RunningCases::resultIn($project, 1);

    expect($written)->toBeInstanceOf(ShardLeft::class)
        ->and($result)->toBeInstanceOf(ShardResult::class)
        ->and($files($global))->toBe(['src/Held.php'])
        ->and(array_map(static fn(array $group): array => [
            $group[0]->triage()->limits()->limit()->seconds(),
            [...$group[0]->setup()->mutators()->except()],
            $files($group[1]),
        ], $ruled))->toBe([
            [60.0, ['default/Plus'], ['src/Money.php']],
            [90.0, [], ['src/Extra.php']],
        ])
        ->and([$settings->triage()->limits()->limit()->seconds(), [...$settings->setup()->mutators()->except()]])
        ->toBe([Flows::settings()->triage()->limits()->limit()->seconds(), []]);
});

it('leaves a shard cannot judge where its paths entries match a unit and the run was composed without the wiring', function () use ($shard): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::of($shard()));
    new Running(
        Flows::adapters($project),
        Flows::settings(PathRule::matching('src/Money.php', Gate::configure()->with(Timeouts::seconds(60)))),
        Flows::setup(),
    )->run($plan, ShardId::of(1), Workspace::results());
    $result = RunningCases::resultIn($project, 1);

    expect($result instanceof ShardResult ? $result->outcome() : $result)->toEqual(CannotJudge::because(
        'The run was composed without the wiring that builds a runner for the units paths entries match.',
    ));
});
