<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;
use NightWorksIO\MutationGate\Tests\Support\Gunzipped;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// Where an engine takes the map's bulk off PHP (ADR-0118), it writes the
// shards' and the verdict's maps from the whole one, and vouches for the maps
// read back; where it declines, the gate writes and reads them itself.

afterEach(function (): void {
    Scratch::sweep();
});

$plan = static fn(): Plan => Plan::of(Revision::ref('head'), Digest::sha256Of('base'), Keys::none(), Shards::of(
    Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/Money.php'))), Seconds::of(1.0), 'one'),
));
$map = static fn(): CoverageMap => CoverageMap::of()
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Other.php'), Line::of(7), TestId::of('OtherTest::d'));

/** An engine that writes every slice it is asked for, as the marker a test can tell from the gate's own map. */
$writing = static fn(string $root): MapEngine => new class ($root) implements MapEngine {
    /** @var list<array{Path, MapSlices}> */
    public private(set) array $splits = [];

    public function __construct(private readonly string $root)
    {
    }

    public function vouches(Contents $text): MapVouch
    {
        return MapVouch::Unvouched;
    }

    public function split(Path $whole, MapSlices $slices): Written
    {
        $this->splits[] = [$whole, $slices];

        foreach ($slices as $slice) {
            Scratch::write($this->root, $slice->into()->value(), 'written by the engine');
        }

        return Written::to($whole->value());
    }
};

it('lets the engine write the shards\' and the verdict\'s maps from the whole one where it does', function () use ($plan, $map, $writing): void {
    $project = Scratch::directory();
    $engine = $writing($project);

    new Handoff(Directory::at($project), HandedMaps::limits(), $engine)
        ->write($plan(), $map(), KillHistory::none(), Unplaced::map());

    expect($engine->splits)->toEqual([[
        Path::of('.mutation-gate/coverage/map.json.gz'),
        MapSlices::of(
            MapSlice::of(Path::of('.mutation-gate/coverage/shard-1/map.json.gz'), Paths::of(Path::of('src/Money.php'))),
            MapSlice::of(Path::of('.mutation-gate/coverage/verdict/map.json.gz'), Paths::of(Path::of('src/Money.php'))),
        ),
    ]])
        ->and(file_get_contents(sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz', $project)))->toBe('written by the engine')
        ->and(file_get_contents(sprintf('%s/.mutation-gate/coverage/verdict/map.json.gz', $project)))->toBe('written by the engine')
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/shard-1/killers.json', $project)))->toBeTrue();
});

it('writes the maps itself where the engine declines, and reads them back vouched for where it vouches', function () use ($plan, $map): void {
    $project = Scratch::directory();
    $declining = new MapEngineFake();
    $handoff = new Handoff(Directory::at($project), HandedMaps::limits(), $declining);
    $handoff->write($plan(), $map(), KillHistory::none(), Unplaced::map());
    $shard = Gunzipped::of((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz', $project)));
    $verdict = Gunzipped::of((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/verdict/map.json.gz', $project)));
    $vouching = new Handoff(Directory::at($project), HandedMaps::limits(), new MapEngineFake([$shard, $verdict]));

    expect(count($declining->splits))->toBe(1)
        ->and($vouching->read(ShardId::of(1)))->toEqual($handoff->read(ShardId::of(1)))
        ->and($vouching->forVerdict())->toEqual($handoff->forVerdict())
        ->and($handoff->read(ShardId::of(1)))->toEqual(
            CoverageMapFile::decode(CoverageMapFile::encode($map()->onlyFor(Paths::of(Path::of('src/Money.php'))), Unplaced::map()), HandedMaps::limits()),
        );
});
