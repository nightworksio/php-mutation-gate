<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Unit;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;

/**
 * A unit as the gate's files write it: its path, and the group or filter
 * that holds it where it is held.
 *
 * @internal the shape of the plan and shard result files
 */
final readonly class UnitRecord
{
    private const string PATH = 'path';

    private const string GROUP = 'group';

    private const string FILTER = 'filter';

    /** @return list<array<string, string>> */
    public static function all(Units $units): array
    {
        return array_map(self::one(...), [...$units]);
    }

    /** @return array<string, string> */
    public static function one(Unit $unit): array
    {
        $by = $unit->judgedBy();

        return [
            self::PATH => $unit->path()->value(),
            ...$by instanceof Group ? [self::GROUP => $by->name()] : [],
            ...$by instanceof Filter ? [self::FILTER => $by->pattern()] : [],
        ];
    }

    /** @throws NotInShape */
    public static function readAll(Node $units): Units
    {
        $read = Units::none();

        foreach ($units->items() as $unit) {
            $read = $read->with(self::read($unit));
        }

        return $read;
    }

    /** @throws NotInShape */
    public static function read(Node $unit): Unit
    {
        $path = Path::of($unit->field(self::PATH)->text());

        $group = $unit->field(self::GROUP);
        $filter = $unit->field(self::FILTER);

        return match (true) {
            $group->isPresent() => Unit::held($path, Group::named($group->text())),
            $filter->isPresent() => Unit::held($path, Filter::matching($filter->text())),
            default => Unit::file($path),
        };
    }
}
