<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\AnalyserWarmUp;
use NightWorksIO\MutationGate\Cli\Flow\PlannedWarmUp;
use NightWorksIO\MutationGate\Cli\Flow\WarmedUp;
use NightWorksIO\MutationGate\Config\CheckTiming;
use NightWorksIO\MutationGate\Config\StaticCheck as StaticCheckBuilder;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

function warmedIdentity(): AnalyserIdentity
{
    return AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
}

function warmedPlan(int $shards): Plan
{
    $planned = [];

    for ($shard = 1; $shard <= $shards; $shard++) {
        $planned[] = Shard::empty(ShardId::of($shard));
    }

    return Plan::of(Revision::ref('head'), Digest::sha256Of('base'), Keys::none(), Shards::of(...$planned));
}

it('warms the analyser up once as the plan is made, and leaves how long it took for the shards of that analyser', function (): void {
    $project = Flows::project();
    $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);

    new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 3))
        ->left(warmedPlan(2));

    expect($checker->warmUps())->toEqual([Paths::none()])
        ->and(PlannedWarmUp::secondsFor(Directory::at($project), warmedIdentity()))->toEqual(Seconds::of(3.0))
        ->and(PlannedWarmUp::secondsFor(Directory::at($project), AnalyserIdentity::of('fake', '2.0.0', Digest::sha256Of('{}'))))->toEqual(NotGiven::value())
        ->and(PlannedWarmUp::secondsFor(Directory::at($project), AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('other'))))->toEqual(NotGiven::value())
        ->and(PlannedWarmUp::secondsFor(Directory::at($project), AnalyserIdentity::of('other', '1.0.0', Digest::sha256Of('{}'))))->toEqual(NotGiven::value());
});

it('warms nothing up and leaves no earlier plan\'s time where no shard checks, the plan holds none, the warm-up fails, or staticCheck.warmAtPlan is off', function (Closure $made): void {
    $project = Flows::project();
    $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);
    $clock = new TickingClock('2026-01-01T00:00:00Z', 3);
    new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(), $clock)->left(warmedPlan(1));

    $made($project);

    expect(PlannedWarmUp::secondsFor(Directory::at($project), warmedIdentity()))->toEqual(NotGiven::value());
})->with([
    'warmAtPlan off' => [static function (string $project): void {
        $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);
        new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(StaticCheckBuilder::warmingInEachShard()), new TickingClock('2026-01-01T00:00:00Z', 3))->left(warmedPlan(1));
    }],
    'no shard checks' => [static function (string $project): void {
        $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);
        new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(CheckTiming::survivorsUnchecked(), CheckTiming::afterTests()), new TickingClock('2026-01-01T00:00:00Z', 3))->left(warmedPlan(1));
    }],
    'no shard' => [static function (string $project): void {
        $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);
        new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 3))->left(warmedPlan(0));
    }],
    'no analyser' => [static function (string $project): void {
        new PlannedWarmUp(Flows::adapters($project), Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 3))->left(warmedPlan(1));
    }],
    'a failed warm-up' => [static function (string $project): void {
        $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), CannotJudge::because('It crashed.'), []), $project);
        new PlannedWarmUp(Flows::adapters($project, [], $checker), Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 3))->left(warmedPlan(1));
    }],
]);

it('reads no time from a file that is not one it left', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/warm-up.json', '{"analyser": "fake", "version": "1.0.0", "config": 1}');

    expect(PlannedWarmUp::secondsFor(Directory::at($project), warmedIdentity()))->toEqual(NotGiven::value());
});

it('has a shard take the plan\'s longer warm-up for a check\'s time, its own where it took longer or the plan made none', function (): void {
    $project = Flows::project();
    $checker = new RecordingChecker(new StaticCheckerFake(warmedIdentity(), Findings::none(), []), $project);
    $adapters = Flows::adapters($project, [], $checker);
    $took = static function () use ($checker, $adapters): Seconds {
        $warm = new AnalyserWarmUp()->of($checker, warmedIdentity(), $adapters, new TickingClock('2026-01-01T00:00:00Z', 2));

        return $warm instanceof WarmedUp ? $warm->took : Seconds::none();
    };
    $unplanned = $took();
    new PlannedWarmUp($adapters, Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 30))->left(warmedPlan(1));
    $planned = $took();
    new PlannedWarmUp($adapters, Flows::settings(), new TickingClock('2026-01-01T00:00:00Z', 1))->left(warmedPlan(1));

    expect($unplanned)->toEqual(Seconds::of(2.0))
        ->and($planned)->toEqual(Seconds::of(30.0))
        ->and($took())->toEqual(Seconds::of(2.0));
});
