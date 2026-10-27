<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use Closure;

use function error_get_last;
use function get_declared_classes;
use function get_included_files;
use function getenv;
use function ini_get;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Grouping\HoldsGroups;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seeder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\EndedInTest;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\ReplayStop;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\StartedWith;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\WarmDatabase;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Test\RunIssues;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\TestSuite;
use PHPUnit\Event\Facade;

use function register_shutdown_function;

/**
 * The Pest adapter's plugin, which Cli\PestPlugin, the one this package
 * lists under `extra.pest.plugins`, runs and hands where a mutant's own
 * process reads the tests whose issues failed its run. In every Pest
 * run it loads the bridges to the registered mutators where the adapter
 * wrote them, it turns `#[Holds]` into `holds:` groups, it records every
 * mutant's result for the adapter where the adapter asked for it, and it
 * guards a run the adapter starts on one mutant through Pest's override. Once
 * Pest has made its mutants it writes each one's order, and in a mutant's own
 * process it runs the tests in that order and names the test that killed the
 * mutant. In a run that lists the tests for the adapter, it names each test
 * the suite loaded. In a child a warm worker forked, it readies the test
 * databases from what the worker handed it (see Warm\Database\WarmDatabase).
 *
 * Pest loads this class before pest-plugin-mutate puts a mutated file in the
 * place of the original, so a mutant of this file would never run, and the
 * gate's own config leaves it out of the trees it mutates. The recording is
 * the recorder's.
 */
final class Plugin implements Bootable, HandlesArguments
{
    private Recorder|Off $recorder = Off::Recording;

    private Guard|Off $guard = Off::Guarding;

    private Killers|Off $killers = Off::NamingKillers;

    private Naming|Off $naming = Off::NamingTests;

    private Seeder|Off $seeder = Off::Ordering;

    private ReplayStop|Off $stop = Off::Stopping;

    /** @param Closure(): RunIssues $issues where a mutant's own process reads the tests whose issues failed its run */
    public function __construct(private readonly Closure $issues)
    {
    }

    /**
     * The guard and the killers read what PHP had loaded when this method
     * starts, before it loads anything itself, as loaded before
     * pest-plugin-mutate's override started: the override starts as that
     * plugin boots, and what boots between the two loads none of the
     * project's code.
     */
    public function boot(): void
    {
        $loaded = get_included_files();
        WarmDatabase::begun();
        Bridged::load(getenv(GateVariable::Mutators->value));
        HoldsGroups::register(TestSuite::getInstance()->tests);
        $this->recorder = Recorder::fromEnvironment();
        $this->guard = Guard::fromEnvironment($loaded);
        $this->killers = Killers::fromEnvironment($loaded, ($this->issues)());
        $this->naming = Naming::fromEnvironment();
        $this->seeder = Seeder::fromEnvironment();
        $this->stop = ReplayStop::fromEnvironment(Facade::instance());

        if ($this->guard instanceof Guard || $this->naming instanceof Naming || $this->killers instanceof Killers) {
            register_shutdown_function($this->finish(...));
        }
    }

    /**
     * Writes what the guard saw, where one watches this run, and the names of
     * the tests, where the adapter asked for them; then, in a mutant's own
     * process that ends as a test runs, names that test its killer and throws,
     * which fails the process, though it may have exited with 0: PHP calls it
     * once the process ends.
     *
     * @throws EndedInTest
     */
    public function finish(): void
    {
        $this->terminate();

        if ($this->naming instanceof Naming) {
            $this->naming->write(get_declared_classes());
        }

        Killers::endedIn($this->killers);
    }

    /**
     * Writes what the guard saw, where one watches this run: as Pest ends a
     * run, and again as the process ends. Pest ends a process a fatal error
     * stops by exiting, which leaves every later shutdown function uncalled,
     * so the guard is written as Pest terminates its plugins first.
     */
    public function terminate(): void
    {
        if ($this->guard instanceof Guard) {
            $opcache = Opcache::of(ini_get(Opcache::CLI), ini_get(Opcache::FILE_CACHE));
            $error = error_get_last();
            $this->guard->write(get_included_files(), $opcache, $error === null ? '' : $error['file']);
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

    public function stop(): ReplayStop|Off
    {
        return $this->stop;
    }

    /**
     * A mutant's own process's arguments, recorded as Pest gave them, then
     * running its tests in the order the plugin wrote for it, or for the copy
     * a replay names, where it wrote one, and on past a failure under a full
     * kill matrix; every other process's as they are.
     *
     * @param  array<int, string> $arguments
     * @return list<string>
     */
    public function handleArguments(array $arguments): array
    {
        $mutated = getenv(Recorder::MUTATED);
        $orderOf = getenv(GateVariable::OrderOf->value);
        StartedWith::record(getenv(GateVariable::Results->value), $mutated, array_values($arguments));
        $ordered = Reordering::of(
            array_values($arguments),
            getenv(GateVariable::Order->value),
            is_string($orderOf) && $orderOf !== '' ? $orderOf : $mutated,
        );

        return EveryKiller::of($ordered, getenv(GateVariable::KillMatrix->value), $mutated);
    }
}
