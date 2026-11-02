<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** How a runner's tests come to hold the paths each `#[Holds]` names (ADR-0005). */
enum HoldsReading
{
    /** Read from the files its tests are in, beside the holding groups it lists. */
    case Written;

    /** Read as it loads its test files, where a group may never form. */
    case AsLoaded;

    /** Read as it lists its tests: each test a `#[Holds]` marks is in the holding group it lists. */
    case Listed;
}
