<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_slice;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Unit\Units;
use Traversable;

/**
 * A shard's units, grouped by what the `paths` entries matching each set (ADR-0074): the units no entry matches
 * first, then each other group in the order its first unit comes, each a shard of its own with the units it holds.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, PathGroup>
 */
final readonly class PathGroups implements IteratorAggregate
{
    /** @param list<PathGroup> $groups */
    private function __construct(private array $groups)
    {
    }

    public static function of(Shard $shard, PathRules $rules): self
    {
        $matched = [];
        $units = [];

        foreach ($shard->units() as $unit) {
            $rule = $rules->for($unit->path());
            $group = $rule->group();
            $matched[$group] = array_key_exists($group, $matched) ? $matched[$group] : $rule;
            $units[$group] = (array_key_exists($group, $units) ? $units[$group] : Units::none())->with($unit);
        }

        $ordered = array_key_exists('', $units) ? ['' => $units['']] + $units : $units;
        $groups = [];

        foreach ($ordered as $group => $held) {
            $groups[] = PathGroup::of(
                $matched[$group],
                Shard::of($shard->id(), $shard->package(), $held, $shard->cost(), $shard->label()),
            );
        }

        return new self($groups);
    }

    /** Whether every unit runs as it would without `paths`: none matched, or the shard holds none. */
    public function isWhole(): bool
    {
        return $this->groups === [] || ($this->groups[0]->rules()->isNone() && array_slice($this->groups, 1) === []);
    }

    /** The units of the group at this place and of every group after it. */
    public function unitsFrom(int $index): Units
    {
        $units = Units::none();

        foreach (array_slice($this->groups, $index) as $group) {
            $units = $units->and($group->shard()->units());
        }

        return $units;
    }

    /** @return Traversable<int, PathGroup> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->groups);
    }
}
