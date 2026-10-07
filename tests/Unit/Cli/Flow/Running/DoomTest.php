<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

$unjudged = RunningCases::unjudged(...);
$resultIn = RunningCases::resultIn(...);
$doomable = RunningCases::doomable(...);
$onPullRequest = RunningCases::onPullRequest(...);
$floored = RunningCases::floored(...);
$asked = RunningCases::asked(...);
$doomOf = RunningCases::doomOf(...);
$moneySurvivor = RunningCases::moneySurvivor(...);

it('stops a pull request\'s shard after the chunk whose survivor makes its run certain to fail, leaving the rest unjudged', function () use (
    $doomable,
    $resultIn,
    $unjudged,
    $onPullRequest,
    $floored,
    $asked,
    $doomOf,
    $moneySurvivor,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php']])
        ->and($unjudged($result))->toBe(['src/Held.php'])
        ->and($doomOf($result))->toBe(['src/Money.php', $moneySurvivor(), 'src', 10_000, 'tree']);
});

it('runs a pull request\'s shard to its end in chunks, in the plan\'s order, while no survivor makes its run certain to fail', function () use (
    $doomable,
    $resultIn,
    $unjudged,
    $onPullRequest,
    $floored,
    $asked,
    $doomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::of(50))), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a shard off a pull request to its end, whatever its survivors', function () use (
    $doomable,
    $resultIn,
    $unjudged,
    $floored,
    $asked,
    $doomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Held.php'], ['src/Money.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a pull request\'s shard to its end where its survivor proves flaky', function () use (
    $doomable,
    $resultIn,
    $unjudged,
    $onPullRequest,
    $floored,
    $asked,
    $doomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a pull request\'s shard to its end where its trees cannot be read, or it holds no tree to a floor', function (object $port) use (
    $doomable,
    $resultIn,
    $unjudged,
    $onPullRequest,
    $asked,
    $doomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $port), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Held.php'], ['src/Money.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
})->with([
    'unreadable trees' => [new TreeSourceFake(CannotJudge::because('No composer.json.'))],
    'security mutators alone' => [Narrowing::none()->toMutators(Mutators::named('Plus'))],
    'one suite\'s tests alone' => [Narrowing::none()->toSuite(SuiteName::of('unit'))],
]);

it('chunks a pull request\'s shard in about two minutes of the work the cost model expects, each chunk at least one unit', function (float $each, array $chunks) use (
    $doomable,
    $onPullRequest,
    $floored,
    $asked,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $files = Units::of(Planned::money(), Unit::file(Path::of('src/Second.php')), Unit::file(Path::of('src/Third.php')));
    $plan = $onPullRequest(Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), $files, Seconds::of(3.0), 'files')));

    new Running(
        Flows::adapters($project, [], $runner, $floored(Floor::of(50)), new CostModelFake(Seconds::of($each))),
        $doomable(),
        Flows::setup(),
    )->run(Planned::handedIn($project, $plan), ShardId::of(1), Workspace::results());

    expect($asked($runner))->toBe($chunks);
})->with([
    'each a minute and a half' => [90.0, [['src/Money.php'], ['src/Second.php'], ['src/Third.php']]],
    'each half a minute' => [30.0, [['src/Money.php', 'src/Second.php', 'src/Third.php']]],
    'each a minute' => [50.0, [['src/Money.php', 'src/Second.php'], ['src/Third.php']]],
]);

it('runs no shard of a plan in one process after one that stopped once its run could not pass', function () use (
    $doomable,
    $resultIn,
    $onPullRequest,
    $floored,
    $asked,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    $written = new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->runAll(Planned::handedIn($project, $onPullRequest(Planned::twoShards())), Workspace::results());

    expect($written)->toBeInstanceOf(Written::class)
        ->and($asked($runner))->toBe([['src/Money.php']])
        ->and($resultIn($project, 1))->toBeInstanceOf(ShardResult::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/2.json', $project)))->toBeFalse();
});

it('runs a pull request\'s shard to its end, whole, where static analysis could still clear its survivor', function (Settings $settings, object ...$ports) use (
    $resultIn,
    $unjudged,
    $onPullRequest,
    $floored,
    $asked,
    $doomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole()), ...$ports), $settings, Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Held.php'], ['src/Money.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
})->with([
    'equivalence.static' => [Flows::settings()],
    'a static check' => [Flows::settings(Equivalence::notProvenStatically()), StaticCheckerFake::findingNothing()],
]);
