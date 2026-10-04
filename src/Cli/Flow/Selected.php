<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;

/**
 * What `affected` found: the tests the change can make fail, the ref the
 * change was read since where one was given, and where the coverage map was
 * measured where a map was read.
 */
final readonly class Selected
{
    private function __construct(
        public AffectedTests $tests,
        public Revision|NotGiven $base,
        public MeasuredAt|Unplaced|NotGiven $map,
    ) {
    }

    public static function of(AffectedTests $tests, Revision|NotGiven $base, MeasuredAt|Unplaced|NotGiven $map): self
    {
        return new self($tests, $base, $map);
    }
}
