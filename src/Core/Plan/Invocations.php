<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Unit\Units;
use Traversable;

/**
 * The runner invocations a shard makes, in order: each held unit alone,
 * against the tests that hold it, then one for all its other units.
 *
 * @implements IteratorAggregate<int, Units>
 */
final readonly class Invocations implements Countable, IteratorAggregate
{
    /** @param list<Units> $invocations */
    private function __construct(private array $invocations)
    {
    }

    public static function of(Units $units): self
    {
        $held = [];
        $rest = Units::none();

        foreach ($units as $unit) {
            $held = $unit->isHeld() ? [...$held, Units::of($unit)] : $held;
            $rest = $unit->isHeld() ? $rest : $rest->with($unit);
        }

        return new self(count($rest) === 0 ? $held : [...$held, $rest]);
    }

    public function count(): int
    {
        return count($this->invocations);
    }

    /** @return Traversable<int, Units> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->invocations);
    }
}
