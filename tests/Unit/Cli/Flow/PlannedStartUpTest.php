<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\PlannedStartUp;
use NightWorksIO\MutationGate\Config\TimeoutSpeedUps;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

function startUpPlan(Unit ...$units): Plan
{
    return Plan::of(Revision::ref('head'), Digest::sha256Of('base'), Keys::none(), Shards::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(...$units), Seconds::of(1.0), 'one'),
    ));
}

function startedUpBy(ScriptedRunner $runner): Identity
{
    $identity = $runner->identity(Flows::adapters(Flows::project())->withheld);

    return $identity instanceof Identity ? $identity : throw new LogicException('The fixture says who it is.');
}

it('measures a mutant\'s start-up once as the plan is made, the fastest of its runs, for the shards of the same runner and platform', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->startingUpIn(Seconds::of(0.9), Seconds::of(0.6), Seconds::of(0.8));
    $identity = startedUpBy($runner);

    new PlannedStartUp(Flows::adapters($project, [], $runner), Flows::settings())
        ->left(startUpPlan(Unit::file(Path::of('src/Money.php'))), Flows::map());

    expect(PlannedStartUp::secondsFor(Directory::at($project), $identity))->toEqual(Seconds::of(0.6))
        ->and(array_map(static fn(array $run): string => $run[0]->value(), $runner->startedUp()))->toBe(['src/Money.php', 'src/Money.php', 'src/Money.php'])
        ->and(PlannedStartUp::secondsFor(Directory::at($project), Identity::of($identity->runner(), $identity->versions(), Digest::sha256Of('arm'))))->toEqual(NotGiven::value())
        ->and(PlannedStartUp::secondsFor(Directory::at($project), Identity::of('other', $identity->versions(), $identity->platform())))->toEqual(NotGiven::value());
});

it('leaves no start-up, and none of an earlier plan, under timeouts.startUpAtPlan: false, for a plan of no unit, or where no run of no test can run', function (Settings $settings, Plan $plan, ScriptedRunner $runner): void {
    $project = Flows::project();
    $identity = startedUpBy($runner);
    new PlannedStartUp(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings())
        ->left(startUpPlan(Unit::file(Path::of('src/Money.php'))), Flows::map());

    new PlannedStartUp(Flows::adapters($project, [], $runner), $settings)->left($plan, Flows::map());

    expect(PlannedStartUp::secondsFor(Directory::at($project), $identity))->toEqual(NotGiven::value());
})->with([
    'measured in each shard' => [Flows::settings(TimeoutSpeedUps::measuringInEachShard()), startUpPlan(Unit::file(Path::of('src/Money.php'))), ScriptedRunner::fixture()],
    'no unit' => [Flows::settings(), startUpPlan(), ScriptedRunner::fixture()],
    'no run of no test' => [Flows::settings(), startUpPlan(Unit::file(Path::of('src/Money.php'))), ScriptedRunner::fixture()->startingUpIn(CannotJudge::because('Pest did not start.'))],
]);

it('reads no start-up from a file that is not one it left', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/start-up.json', '{"runner": "pest", "platform": "x"}');

    expect(PlannedStartUp::secondsFor(Directory::at($project), Identity::of('pest', Versions::none(), Digest::sha256Of('x'))))->toEqual(NotGiven::value());
});
