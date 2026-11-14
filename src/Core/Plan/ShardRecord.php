<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;

/**
 * A shard as the plan file holds it in `shards`: its number, its label, its
 * cost in seconds and what it was estimated at, its package, its units, the
 * part of the test files whose tests judge them where the run cuts them (see
 * TestPartRecord), and, in a run whose shards pull their work, the job that
 * lists its items.
 *
 * @internal the shape of a shard in the plan file
 *
 * @phpstan-import-type Written from UnitRecord as UnitWritten
 *
 * @phpstan-type Written array<string, int|string|float|list<UnitWritten>>
 */
final readonly class ShardRecord
{
    /** The job that lists an item of a run whose shards pull their work first (ADR-0134). */
    private const string WORKER = 'worker';

    /** @return Written */
    public static function of(Shard $shard): array
    {
        return [
            'id' => $shard->id()->number(),
            'label' => $shard->label(),
            'seconds' => $shard->cost()->seconds(),
            ...EstimateRecord::of($shard->estimate()),
            'package' => $shard->package()->path()->value(),
            'units' => UnitRecord::all($shard->units()),
            ...TestPartRecord::of($shard->testPart()),
            ...$shard->isPulled() ? [self::WORKER => $shard->worker()->number()] : [],
        ];
    }

    /** @throws NotInShape */
    public static function read(Node $shard): Shard
    {
        $id = $shard->field('id')->integer();

        $read = Shard::of(
            $id > 0 ? ShardId::of($id) : throw NotInShape::at($shard->field('id')->at(), 'a shard number'),
            Package::at(Path::of($shard->field('package')->text())),
            UnitRecord::readAll($shard->field('units')),
            Seconds::of($shard->field('seconds')->number()),
            $shard->field('label')->text(),
        );

        $worker = $shard->field(self::WORKER);
        $read = $read->estimated(EstimateRecord::read($shard, $read->cost()))->inPart(TestPartRecord::read($shard));

        return match (true) {
            ! $worker->isPresent() => $read,
            $worker->integer() > 0 => $read->workedBy(ShardId::of($worker->integer())),
            default => throw NotInShape::at($worker->at(), 'a shard number'),
        };
    }
}
