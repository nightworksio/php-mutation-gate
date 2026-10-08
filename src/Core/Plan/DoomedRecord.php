<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;

/**
 * The survivor a shard stopped on once its run could not pass (ADR-0008,
 * decision 6), as the shard's result holds it under `doomed`: `unit`, the
 * path of the unit it is a mutant of, `mutant`, its id, `tree`, the path of
 * the tree that holds that unit, `floor`, the floor of 100 it fails, and
 * `why`, which floor that is, `tree` or `newCode`. A shard that ran to its
 * end holds none. A CI step that cancels the run's other shards reads it.
 *
 * @internal the shape of a shard result's `doomed`
 */
final readonly class DoomedRecord
{
    public const string SECTION = 'doomed';

    public const string MUTANT = 'mutant';

    public const string TREE = 'tree';

    public const string FLOOR = 'floor';

    /** @return array{}|array{unit: string, mutant: string, tree: string, floor: int|float, why: string} */
    public static function of(Doomed|Undoomed $doomed): array
    {
        return $doomed instanceof Undoomed ? [] : [
            ShardResultFile::UNIT => $doomed->unit()->value(),
            self::MUTANT => $doomed->mutant()->value(),
            self::TREE => $doomed->tree()->value(),
            self::FLOOR => $doomed->floor()->written(),
            ShardResultFile::WHY => $doomed->by()->value,
        ];
    }

    /**
     * The survivor a result names, none where it names none.
     *
     * @throws NotInShape
     */
    public static function read(Node $section): Doomed|Undoomed
    {
        if (! $section->isPresent()) {
            return Undoomed::run();
        }

        $named = $section->field(self::MUTANT);
        $mutant = MutantId::parse($named->text());
        $why = $section->field(ShardResultFile::WHY);

        return Doomed::of(
            Path::of($section->field(ShardResultFile::UNIT)->text()),
            $mutant instanceof MutantId ? $mutant : throw NotInShape::at($named->at(), 'a mutant id'),
            Path::of($section->field(self::TREE)->text()),
            self::floorIn($section->field(self::FLOOR)),
            DoomedBy::tryFrom($why->text()) ?? throw NotInShape::at($why->at(), 'why a survivor dooms a run'),
        );
    }

    /** @throws NotInShape */
    private static function floorIn(Node $floor): Floor
    {
        $percentage = Percentage::parse($floor->number());

        return $percentage instanceof Percentage
            ? Floor::ofHundredths($percentage->hundredths())
            : throw NotInShape::at($floor->at(), 'a percentage');
    }
}
