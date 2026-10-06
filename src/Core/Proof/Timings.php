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
 * later replaces it. What a run learns is first smoothed over what was held:
 * an exponentially weighted moving average of a unit's measurements by the
 * same runner (ADR-0006, decision 4).
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
        return new self(self::newest([], $timings));
    }

    public function with(Timing $timing): self
    {
        return new self(self::newest($this->timings, [$timing]));
    }

    /** These timings and another's, each unit keeping its newest. */
    public function and(self $other): self
    {
        return new self(self::newest($this->timings, $other->timings));
    }

    /**
     * These timings, as a run measured them, each smoothed over the unit's
     * timing held from an earlier measurement by the same runner, as
     * `Timing::smoothedOver()` weighs them. A unit with none held,
     * or one an other runner measured or measured later, takes its newest
     * measurement as it is.
     */
    public function smoothedOver(self $held): self
    {
        $smoothed = [];

        foreach ($this->timings as $unit => $timing) {
            $before = array_key_exists($unit, $held->timings) ? $held->timings[$unit] : $timing;
            $blends = $before->runner() === $timing->runner() && $timing->at()->isAfter($before->at());
            $smoothed[] = $blends ? $timing->smoothedOver($before) : $timing;
        }

        return self::of(...$smoothed);
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

    /**
     * Timings held, with more added one after another, each unit keeping its
     * newest.
     *
     * @param  array<string, Timing> $held by unit
     * @param  array<Timing>         $more
     * @return array<string, Timing> by unit
     */
    private static function newest(array $held, array $more): array
    {
        foreach ($more as $timing) {
            $unit = $timing->unit()->value();

            if (! array_key_exists($unit, $held) || ! $held[$unit]->at()->isAfter($timing->at())) {
                $held[$unit] = $timing;
            }
        }

        return $held;
    }
}
