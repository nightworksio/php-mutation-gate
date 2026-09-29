<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;

use function sprintf;

/**
 * A plan as `.mutation-gate/plan.json` holds it, `"format": 1`: the commit it
 * was made on, every considered unit's key, and the shards, with the digest
 * of all of that. A plan that cannot be read, or whose digest does not match
 * what it holds, is refused: a shard never guesses at its units.
 *
 * @internal the shape of the plan file
 */
final readonly class PlanFile
{
    private const int FORMAT = 1;

    private const string DIGEST = 'digest';

    public static function encode(Plan $plan): string
    {
        return Json::encode([...self::body($plan), self::DIGEST => self::digestOf($plan)->value()]);
    }

    public static function decode(string $json): Plan|CannotJudge
    {
        try {
            return self::planIn(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(
                'The plan cannot be read, so no shard can follow it: %s',
                $refused->getMessage(),
            ));
        }
    }

    /** The digest of everything a plan holds. */
    public static function digestOf(Plan $plan): Digest
    {
        return Digest::sha256Of(Json::encode(self::body($plan)));
    }

    /** @return array<string, mixed> */
    private static function body(Plan $plan): array
    {
        return [
            'format' => self::FORMAT,
            'commit' => $plan->commit()->name(),
            'keys' => KeysRecord::of($plan->keys()),
            'shards' => array_map(self::shard(...), [...$plan]),
        ];
    }

    /** @return array<string, mixed> */
    private static function shard(Shard $shard): array
    {
        return [
            'id' => $shard->id()->number(),
            'label' => $shard->label(),
            'seconds' => $shard->cost()->seconds(),
            'package' => $shard->package()->path()->value(),
            'units' => UnitRecord::all($shard->units()),
        ];
    }

    /** @throws NotInShape */
    private static function planIn(Node $file): Plan|CannotJudge
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $shards = Shards::none();

        foreach ($file->field('shards')->items() as $shard) {
            $shards = $shards->with(self::shardIn($shard));
        }

        $plan = Plan::of(
            Revision::ref($file->field('commit')->text()),
            KeysRecord::read($file->field('keys')),
            $shards,
        );

        return $plan->digest()->value() === $file->field(self::DIGEST)->text()
            ? $plan
            : CannotJudge::because(
                'The plan does not match its digest, so it was changed after it was made. Plan again.',
            );
    }

    /** @throws NotInShape */
    private static function shardIn(Node $shard): Shard
    {
        $id = $shard->field('id')->integer();

        return Shard::of(
            $id > 0 ? ShardId::of($id) : throw NotInShape::at($shard->field('id')->at(), 'a shard number'),
            Package::at(Path::of($shard->field('package')->text())),
            UnitRecord::readAll($shard->field('units')),
            Seconds::of($shard->field('seconds')->number()),
            $shard->field('label')->text(),
        );
    }
}
