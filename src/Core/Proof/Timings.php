<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Traversable;

/**
 * One timing per unit; a unit's newest timing replaces its older one.
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
        $timings = $this->timings;
        $timings[$timing->unit()->value()] = $timing;

        return new self($timings);
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
