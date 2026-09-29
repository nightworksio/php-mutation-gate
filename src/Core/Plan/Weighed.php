<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/** A unit to be cut into a shard, the package it runs in, and what the cost model expects it to take. */
final readonly class Weighed
{
    private function __construct(private Unit $unit, private Package $package, private Seconds $cost)
    {
    }

    public static function of(Unit $unit, Package $package, Seconds $cost): self
    {
        return new self($unit, $package, $cost);
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
        return $this->cost;
    }
}
