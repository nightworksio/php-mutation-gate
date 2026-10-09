<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;

/**
 * The mutators a plan's run leaves out of which units it runs, as the plan
 * file holds them under `pruned` (ADR-0025, decision 1): a list of two
 * lists, the mutators by the runner's names for them, then the units'
 * paths. A plan that prunes nothing holds no such field, and one whose
 * field is not so shaped is refused, since every shard must leave out the
 * same mutants.
 *
 * @internal the shape of the `pruned` field of the plan file
 *
 * @phpstan-type Written array{list<string>, list<string>}
 */
final readonly class PrunedRecord
{
    public const string FIELD = 'pruned';

    /** @return array{pruned?: Written} the field, where the plan prunes anything */
    public static function field(Pruned $pruned): array
    {
        return $pruned->isNone() ? [] : [self::FIELD => self::of($pruned)];
    }

    /** @return Written */
    public static function of(Pruned $pruned): array
    {
        return [
            [...$pruned->mutators()],
            array_map(static fn(Path $file): string => $file->value(), [...$pruned->files()]),
        ];
    }

    /**
     * What a plan's `pruned` field holds; nothing pruned where it is absent.
     *
     * @throws NotInShape
     */
    public static function read(Node $field): Pruned
    {
        if (! $field->isPresent()) {
            return Pruned::none();
        }

        $pair = $field->items();

        if (array_keys($pair) !== [0, 1]) {
            throw NotInShape::at($field->at(), 'a list of the mutators pruned and a list of the units pruned');
        }

        return Pruned::of(
            MutatorNames::of(...array_map(static fn(Node $name): string => $name->text(), $pair[0]->items())),
            Paths::of(...array_map(static fn(Node $path): Path => Path::of($path->text()), $pair[1]->items())),
        );
    }
}
