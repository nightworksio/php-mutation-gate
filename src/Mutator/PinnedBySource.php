<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use NightWorksIO\MutationGate\Core\Mutant\SourcePin;

/**
 * A mutator whose mutant behaves the same in every test, so only a test that
 * runs the code and then reads its file can kill it. It names what that test
 * must find there, which `stub` writes the test from. It stands down under a
 * runner that shows the mutant only to an include, as Infection does
 * (ADR-0021, decision 19).
 */
interface PinnedBySource
{
    /** What a test that reads the mutant's file must find there. */
    public function pin(): SourcePin;
}
