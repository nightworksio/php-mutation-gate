<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** What pest-plugin-mutate's filter adds for a covering test it cannot name: nothing, so it drops the test. */
enum Unselectable
{
    case Test;
}
