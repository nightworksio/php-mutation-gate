<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_key_exists;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use Traversable;

/**
 * What the plan measured of each held unit's holding tests, run alone under
 * coverage once for the whole run (ADR-0161): the tests of theirs that run
 * it, the sentence that says which of its lines they miss, or why they
 * cannot run on their own, by the held path. Every shard holding the unit
 * reads it from here and runs nothing.
 *
 * @implements IteratorAggregate<string, TestIds|NotCovered|CannotJudge>
 */
final readonly class MeasuredHolds implements IteratorAggregate
{
    /** @param array<string, TestIds|NotCovered|CannotJudge> $held by the held path */
    private function __construct(private array $held)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with one more held unit measured: its covering tests, its misses, or why its tests cannot run alone. */
    public function with(Unit $unit, Covered|NotCovered|CannotJudge $measured): self
    {
        return new self([
            ...$this->held,
            $unit->path()->value() => $measured instanceof Covered ? $measured->tests() : $measured,
        ]);
    }

    /** Whether the plan measured every held unit among these. */
    public function measures(Units $units): bool
    {
        foreach ($units as $unit) {
            if ($unit->isHeld() && ! array_key_exists($unit->path()->value(), $this->held)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The checks of the held units among these, as each shard's own run
     * alone would have made them; or why the tests that hold the first that
     * failed cannot run on their own.
     */
    public function checksOf(Units $units): HeldChecks|CannotJudge
    {
        $checks = HeldChecks::none();

        foreach ($units as $unit) {
            $path = $unit->path()->value();
            $measured = $unit->isHeld() && array_key_exists($path, $this->held)
                ? $this->held[$path]
                : NotGiven::value();

            if ($measured instanceof CannotJudge) {
                return $measured;
            }

            $checks = match (true) {
                $measured instanceof TestIds => $checks->with(Covered::by($unit, $measured)),
                $measured instanceof NotCovered => $checks->with(NotCovered::because($unit, $measured->why())),
                default => $checks,
            };
        }

        return $checks;
    }

    /** @return Traversable<string, TestIds|NotCovered|CannotJudge> by the held path */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->held);
    }
}
