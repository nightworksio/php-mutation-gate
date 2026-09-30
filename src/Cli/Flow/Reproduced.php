<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/** A recorded mutant run again, by the tests that judge its unit. */
final readonly class Reproduced
{
    public function __construct(
        public Recorded $recorded,
        public WholeSuite|Group|Filter $judgedBy,
        public Reproduction $now,
    ) {
    }
}
