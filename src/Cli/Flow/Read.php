<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;

/** A coverage map read from where a job left it, with where it says it was measured. */
final readonly class Read
{
    private function __construct(public CoverageMap $map, public MeasuredAt|Unplaced $at)
    {
    }

    public static function of(CoverageMap $map, MeasuredAt|Unplaced $at): self
    {
        return new self($map, $at);
    }
}
