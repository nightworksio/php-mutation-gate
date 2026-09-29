<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_filter;
use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Traversable;

/**
 * One timing per unit, the newest measurement winning: a timing measured
 * before the one held is dropped, and one measured at the same instant or
 * later replaces it.
 *
 * @implements IteratorAggregate<int, Timing>
 */
final readonly class Timings implements Countable, IteratorAggregate
{
    /** @param array<string, Timing> $timings by unit */
    private function __construct(private array $timings)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Timing ...$timings): self
    {
        $collected = self::none();

        foreach ($timings as $timing) {
            $collected = $collected->with($timing);
        }

        return $collected;
    }

    public function with(Timing $timing): self
    {
        $unit = $timing->unit()->value();

        if (array_key_exists($unit, $this->timings) && $this->timings[$unit]->at()->isAfter($timing->at())) {
            return $this;
        }

        $timings = $this->timings;
        $timings[$unit] = $timing;

        return new self($timings);
    }

    /** These timings and another's, each unit keeping its newest. */
    public function and(self $other): self
    {
        $merged = $this;

        foreach ($other->timings as $timing) {
            $merged = $merged->with($timing);
        }

        return $merged;
    }

    /** Only the timings of these units, which are the ones that still exist. */
    public function onlyFor(Paths $units): self
    {
        return new self(array_filter($this->timings, static fn(Timing $timing): bool => $units->has($timing->unit())));
    }

    public function secondsFor(Path $unit): Seconds|Unmeasured
    {
        return array_key_exists($unit->value(), $this->timings)
            ? $this->timings[$unit->value()]->seconds()
            : Unmeasured::duration();
    }

    public function count(): int
    {
        return count($this->timings);
    }

    /** @return Traversable<int, Timing> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->timings));
    }
}
