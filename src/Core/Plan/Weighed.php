<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * A unit to be cut into a shard, the package it runs in, what the cost model
 * expects mutating it to take, and the limit of the unmutated control its
 * kills ask for, where they ask for one (ADR-0008).
 *
 * @internal
 */
final readonly class Weighed
{
    private function __construct(
        private Unit $unit,
        private Package $package,
        private Estimated $estimated,
        private Seconds $control,
    ) {
    }

    public static function of(Unit $unit, Package $package, Estimated $estimated): self
    {
        return new self($unit, $package, $estimated, Seconds::none());
    }

    /** The unit, its kills controlled by a run allowed this long. */
    public function controlledWithin(Seconds $control): self
    {
        return new self($this->unit, $this->package, $this->estimated, $control);
    }

    /**
     * The unit, weighed for a shard whose tests are one of this many parts of
     * the test files: its cost shared alike among the parts, on the same basis.
     */
    public function sharedAmong(TestPart $part): self
    {
        $share = Seconds::of($this->cost()->seconds() / $part->parts());

        return new self($this->unit, $this->package, Estimated::of($share, $this->estimated->basis()), $this->control);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function package(): Package
    {
        return $this->package;
    }

    public function cost(): Seconds
    {
        return $this->estimated->seconds();
    }

    /** The limit of the unmutated control the unit's kills ask for: none where they ask for none. */
    public function control(): Seconds
    {
        return $this->control;
    }

    /** What the cost model expects, and what that rests on. */
    public function estimated(): Estimated
    {
        return $this->estimated;
    }
}
