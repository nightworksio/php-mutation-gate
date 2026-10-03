<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;
use function get_declared_classes;
use function get_included_files;
use function getenv;
use function ini_get;

use NightWorksIO\MutationGate\Adapter\Pest\Grouping\HoldsGroups;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seeder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\TestSuite;

use function register_shutdown_function;

/**
 * The Pest plugin this package lists under `extra.pest.plugins`. In every Pest
 * run it turns `#[Holds]` into `holds:` groups, it records every mutant's
 * result for the adapter where the adapter asked for it, and it guards a run
 * the adapter starts on one mutant through Pest's override. Once Pest has made
 * its mutants it writes each one's order, and in a mutant's own process it
 * runs the tests in that order and names the test that killed the mutant. In
 * a run that lists the tests for the adapter, it names each test the suite
 * loaded.
 *
 * Pest loads this class before pest-plugin-mutate puts a mutated file in the
 * place of the original, so a mutant of this file would never run. It holds
 * no line a mutator changes, and the recording is the recorder's.
 */
final class Plugin implements Bootable, HandlesArguments
{
    private Recorder|Off $recorder = Off::Recording;

    private Guard|Off $guard = Off::Guarding;

    private Killers|Off $killers = Off::NamingKillers;

    private Naming|Off $naming = Off::NamingTests;

    private Seeder|Off $seeder = Off::Ordering;

    public function boot(): void
    {
        HoldsGroups::register(TestSuite::getInstance()->tests);
        $this->recorder = Recorder::fromEnvironment();
        $this->guard = Guard::fromEnvironment();
        $this->killers = Killers::fromEnvironment();
        $this->naming = Naming::fromEnvironment();
        $this->seeder = Seeder::fromEnvironment();

        if ($this->guard instanceof Guard || $this->naming instanceof Naming) {
            register_shutdown_function($this->finish(...));
        }
    }

    /**
     * Writes what the guard saw, where one watches this run, and the names of
     * the tests, where the adapter asked for them: PHP calls it once the run
     * ends.
     */
    public function finish(): void
    {
        if ($this->guard instanceof Guard) {
            $opcache = Opcache::of(ini_get(Opcache::CLI), ini_get(Opcache::FILE_CACHE));
            $this->guard->write(get_included_files(), $opcache);
        }

        if ($this->naming instanceof Naming) {
            $this->naming->write(get_declared_classes());
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

    public function killers(): Killers|Off
    {
        return $this->killers;
    }

    public function naming(): Naming|Off
    {
        return $this->naming;
    }

    public function seeder(): Seeder|Off
    {
        return $this->seeder;
    }

    /**
     * A mutant's own process's arguments, running its tests in the order the
     * plugin wrote for it where it wrote one, and every other process's as
     * they are.
     *
     * @param  array<int, string> $arguments
     * @return list<string>
     */
    public function handleArguments(array $arguments): array
    {
        return Reordering::of(array_values($arguments), getenv(GateVariable::Order->value), getenv(Recorder::MUTATED));
    }
}
