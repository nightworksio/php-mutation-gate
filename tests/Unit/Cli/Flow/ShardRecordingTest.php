<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Cli\Flow\ShardRecording;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RecordedShard;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose shard 1 streams recorded `MoneyTest::adds` running `src/Money.php` line 11. */
function recordingProject(): string
{
    $project = Flows::project();
    RecordedShard::in($project);

    return $project;
}

/** An empty plan, planned without a fresh map or with one. */
function recordingPlan(bool $early): Plan
{
    $plan = Plan::of(Revision::ref('HEAD'), Digest::sha256Of('recorded'), Keys::none(), Shards::of());

    return $early ? $plan->briefed($plan->briefing()->plannedEarly()) : $plan;
}

it('leaves the map and the suite the streams of a run planned without a fresh map recorded beside its result', function (): void {
    $project = recordingProject();

    new ShardRecording(Flows::adapters($project), Flows::settings())->left(recordingPlan(early: true), ShardId::of(1), Path::of('results'));
    $map = CoverageMapFile::kept((string) file_get_contents(sprintf('%s/results/1.recorded.json.gz', $project)), MapLimits::standard());
    $suite = StreamedSuite::decoded((string) file_get_contents(sprintf('%s/results/1.suite.json', $project)));

    expect($map instanceof KeptMap ? array_map(static fn(TestId $test): string => $test->value(), [...$map->map()->testsCovering(Path::of('src/Money.php'), Line::of(11), Line::of(11))]) : $map)
        ->toBe(['MoneyTest::adds'])
        ->and($suite instanceof StreamedSuite ? $suite->secondsByTest() : $suite)->toBe(['MoneyTest::adds' => 0.5]);
});

it('leaves nothing for a run planned without a fresh map whose streams recorded nothing', function (): void {
    $project = Flows::project();

    new ShardRecording(Flows::adapters($project), Flows::settings())->left(recordingPlan(early: true), ShardId::of(1), Path::of('results'));

    expect(sprintf('%s/results', $project))->not->toBeDirectory();
});

it('takes the tests a quick round\'s streams ran whole into the kept map, and leaves nothing beside the result', function (): void {
    $project = recordingProject();
    $kept = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(30), TestId::of('MoneyTest::adds'))->timed(TestId::of('MoneyTest::adds'), Seconds::of(1.0));
    Scratch::write($project, CoverageMapFile::in(Workspace::coverage())->value(), CoverageMapFile::encode($kept, MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false)));
    $recording = new ShardRecording(Flows::adapters($project)->looping(ProcessCount::of(1), LocalLoop::of(LocalWork::of())->quickly()), Flows::settings());

    $recording->left(recordingPlan(early: false), ShardId::of(1), Path::of('results'));
    $read = CoverageMapFile::kept((string) file_get_contents(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::coverage())->value())), MapLimits::standard());

    expect($recording->recordsLines())->toBeTrue()
        ->and($read instanceof KeptMap ? [count($read->map()->testsCovering(Path::of('src/Money.php'), Line::of(11), Line::of(11))), count($read->map()->testsCovering(Path::of('src/Money.php'), Line::of(30), Line::of(30)))] : $read)
        ->toBe([1, 0])
        ->and(sprintf('%s/results', $project))->not->toBeDirectory();
});

it('keeps no map for a quick round where the last round left none', function (): void {
    $project = recordingProject();
    $recording = new ShardRecording(Flows::adapters($project)->looping(ProcessCount::of(1), LocalLoop::of(LocalWork::of())->quickly()), Flows::settings());

    $recording->left(recordingPlan(early: false), ShardId::of(1), Path::of('results'));

    expect(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::coverage())->value()))->not->toBeFile();
});

it('takes no test a quick round\'s stream ran in its own process into the kept map', function (): void {
    $project = recordingProject();
    Scratch::write($project, sprintf('%s/recorded-1-1.jsonl', Workspace::recording(ShardId::of(1))->value()), StreamLine::covered(
        'MoneyTest::forked',
        [sprintf('%s/src/Money.php', $project) => [12]],
        new TestEnded(passed: true, troubled: false, own: true, seconds: 0.5),
    ));
    $kept = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(30), TestId::of('MoneyTest::adds'))->timed(TestId::of('MoneyTest::adds'), Seconds::of(1.0));
    Scratch::write($project, CoverageMapFile::in(Workspace::coverage())->value(), CoverageMapFile::encode($kept, MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false)));

    new ShardRecording(Flows::adapters($project)->looping(ProcessCount::of(1), LocalLoop::of(LocalWork::of())->quickly()), Flows::settings())
        ->left(recordingPlan(early: false), ShardId::of(1), Path::of('results'));
    $read = CoverageMapFile::kept((string) file_get_contents(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::coverage())->value())), MapLimits::standard());

    expect($read instanceof KeptMap ? count($read->map()->testsCovering(Path::of('src/Money.php'), Line::of(12), Line::of(12))) : $read)->toBe(0);
});

it('makes a shard\'s recording directory under the project, and clears what its streams recorded before', function (): void {
    $project = recordingProject();
    $shard = Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/Money.php'))), Seconds::of(1.0), 'src');
    $fresh = Flows::project();
    $recording = new ShardRecording(Flows::adapters($project), Flows::settings());

    $directory = $recording->directory($shard);
    $made = new ShardRecording(Flows::adapters($fresh), Flows::settings())->directory($shard);

    expect($directory)->toBe(sprintf('%s/%s', $project, Workspace::recording(ShardId::of(1))->value()))
        ->and(glob(sprintf('%s/*', $directory)))->toBe([])
        ->and($made)->toBeDirectory()
        ->and(str_starts_with($made, $fresh))->toBeTrue();
});
