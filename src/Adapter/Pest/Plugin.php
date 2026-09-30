<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Grouping\HoldsGroups;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use Pest\Contracts\Plugins\Bootable;
use Pest\TestSuite;

/**
 * The Pest plugin this package lists under `extra.pest.plugins`. In every Pest
 * run it turns `#[Holds]` into `holds:` groups, and it records every mutant's
 * result for the adapter where the adapter asked for it.
 *
 * Pest loads this class before pest-plugin-mutate puts a mutated file in the
 * place of the original, so a mutant of this file would never run. It holds
 * no line a mutator changes, and the recording is the recorder's.
 */
final class Plugin implements Bootable
{
    private Recorder|Off $recorder = Off::Recording;

    public function boot(): void
    {
        HoldsGroups::register(TestSuite::getInstance()->tests);
        $this->recorder = Recorder::fromEnvironment();
    }

    public function recorder(): Recorder|Off
    {
        return $this->recorder;
    }
}
