<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_values;
use function count;

use Countable;
use Generator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The units one shard mutates, in the order it takes them, what they cost
 * together, and the part of the test files whose tests judge them. A run of
 * no units is an empty shard.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, Weighed>
 */
final readonly class Run implements Countable, IteratorAggregate
{
    /** @param list<Weighed> $units */
    private function __construct(private array $units, private TestPart $part)
    {
    }

    public static function of(Weighed ...$units): self
    {
        return new self(array_values($units), TestPart::whole());
    }

    /** These units, each weighed at its share of this part of the test files, which judges them (ADR-0117). */
    public static function inPart(TestPart $part, Weighed ...$units): self
    {
        return new self(
            array_map(static fn(Weighed $unit): Weighed => $unit->sharedAmong($part), array_values($units)),
            $part,
        );
    }

    public function cost(): Seconds
    {
        $seconds = 0.0;

        foreach ($this->units as $unit) {
            $seconds += $unit->cost()->seconds();
        }

        return Seconds::of($seconds);
    }

    /** The shard that mutates this run, under this label. */
    public function shard(ShardId $id, string $label): Shard
    {
        $estimate = ShardEstimate::none();

        foreach ($this->units as $unit) {
            $estimate = $estimate->with($unit->estimated());
        }

        return $this->units === [] ? Shard::empty($id) : Shard::of(
            $id,
            $this->units[0]->package(),
            Units::of(...array_map(static fn(Weighed $unit): Unit => $unit->unit(), $this->units)),
            $this->cost(),
            $label,
        )->estimated($estimate)->inPart($this->part);
    }

    public function count(): int
    {
        return count($this->units);
    }

    /** @return Generator<int, Weighed> */
    public function getIterator(): Generator
    {
        yield from $this->units;
    }
}
