<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/**
 * The map a shard's run opens on, with how long each step that readied it
 * took; or why the shard cannot open on it, or that it opens on its own
 * suite, with no step timed.
 *
 * @internal
 */
final readonly class OpenedMap
{
    private function __construct(private CoverageMap|Unshared|CannotJudge $map, private StepTimes $steps)
    {
    }

    public static function readied(CoverageMap $map, StepTimes $steps): self
    {
        return new self($map, $steps);
    }

    public static function unopened(Unshared|CannotJudge $why): self
    {
        return new self($why, StepTimes::none());
    }

    public function map(): CoverageMap|Unshared|CannotJudge
    {
        return $this->map;
    }

    public function steps(): StepTimes
    {
        return $this->steps;
    }
}
