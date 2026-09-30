<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function get_included_files;

use NightWorksIO\MutationGate\Adapter\Pest\Grouping\HoldsGroups;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Opcache;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use Pest\Contracts\Plugins\Bootable;
use Pest\TestSuite;

use function register_shutdown_function;

/**
 * The Pest plugin this package lists under `extra.pest.plugins`. In every Pest
 * run it turns `#[Holds]` into `holds:` groups, it records every mutant's
 * result for the adapter where the adapter asked for it, and it guards a run
 * the adapter starts on one mutant through Pest's override.
 *
 * Pest loads this class before pest-plugin-mutate puts a mutated file in the
 * place of the original, so a mutant of this file would never run. It holds
 * no line a mutator changes, and the recording is the recorder's.
 */
final class Plugin implements Bootable
{
    private Recorder|Off $recorder = Off::Recording;

    private Guard|Off $guard = Off::Guarding;

    public function boot(): void
    {
        HoldsGroups::register(TestSuite::getInstance()->tests);
        $this->recorder = Recorder::fromEnvironment();
        $this->guard = Guard::fromEnvironment();

        if ($this->guard instanceof Guard) {
            register_shutdown_function($this->finish(...));
        }
    }

    /** Writes what the guard saw, where one watches this run: Pest calls it once the run ends. */
    public function finish(): void
    {
        if ($this->guard instanceof Guard) {
            $this->guard->write(get_included_files(), Opcache::current());
        }
    }

    public function recorder(): Recorder|Off
    {
        return $this->recorder;
    }

    public function guard(): Guard|Off
    {
        return $this->guard;
    }
}
