<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_shift;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\Remaining;

/** A machine that says these readings in turn, and the last one again once they run out. */
final class ScriptedGauge implements Gauge
{
    /** @var non-empty-list<Remaining> */
    private array $readings;

    public function __construct(Remaining $first, Remaining ...$later)
    {
        $this->readings = [$first, ...array_values($later)];
    }

    public function remaining(): Remaining
    {
        return count($this->readings) > 1 ? array_shift($this->readings) : $this->readings[0];
    }
}
