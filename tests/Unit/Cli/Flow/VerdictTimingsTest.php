<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\VerdictTimings;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

/** The results every shard of a plan left in a project. */
function verdictTimingsRead(Plan $plan, string $project): Results
{
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    return $results instanceof Results ? $results : throw new RuntimeException($results->why());
}

afterEach(function (): void {
    Scratch::sweep();
});

it('times each shard from its start to its result, with its steps, and the verdict, estimating what the run took', function (): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new TickingClock('2026-09-30T12:00:00+00:00', 3),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
    );
    $running = new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), $setup);
    $running->run($plan, ShardId::of(1), Workspace::results());
    $running->run($plan, ShardId::of(2), Workspace::results());
    $timings = VerdictTimings::of(
        'github:1/1',
        verdictTimingsRead($plan, $project),
        new DateTimeImmutable('2026-09-30T13:00:00+00:00'),
        new DateTimeImmutable('2026-09-30T13:00:30+00:00'),
        Seconds::of(60.0),
    );
    $shards = [...$timings->shards()];
    $spent = array_map(static fn(ShardTiming $shard): float => $shard->whole()->duration()->seconds(), $shards);
    $verdict = $timings->verdict();

    expect(array_map(static fn(ShardTiming $shard): int => $shard->shard(), $shards))->toBe([1, 2])
        ->and(array_map(static fn(ShardTiming $shard): string => $shard->whole()->start()->value(), $shards))
        ->toBe(['2026-09-30T12:00:00Z', '2026-09-30T12:00:36Z'])
        ->and(array_map(static fn(StepTime $step): Step => $step->step(), [...$shards[0]->steps()]))->toContain(Step::Mutation)
        ->and($timings->run())->toBe('github:1/1')
        ->and($verdict instanceof Phase ? [$verdict->start()->value(), $verdict->duration()->seconds()] : [])
        ->toBe(['2026-09-30T13:00:00Z', 30.0])
        ->and($timings->spent()->wall())->toEqual(Seconds::of(3630.0 + 60.0))
        ->and($timings->spent()->runner())->toEqual(Seconds::of(30.0 + 60.0 + $spent[0] + 60.0 + $spent[1] + 60.0))
        ->and($timings->spent()->isMeasured())->toBeFalse();
});
