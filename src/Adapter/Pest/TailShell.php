<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * The shell the verification tail runs Pest in where `runner.workers` forks
 * it under `pest.patch` (ADR-0070): the runs it starts side by side, the
 * unmutated controls, baselines and trials, each run by the warm worker of
 * its place, which the gate's own process starts for the runs and ends once
 * they are done, as Pest's parent does for a mutation run (ADR-0023), with
 * nothing an earlier run left in their directory. The workers start PHP
 * with the ini files the runs scan, so a memory cap reaches a forked run as
 * it reaches a fresh one; runs that scan others fork from workers of their
 * own. Under `pest.bootTail`, a run of every test file of its suites is run
 * by a worker that booted the project, those suites' test files loaded
 * (ADR-0075). A run
 * whose worker forks it no child, or is gone, starts fresh in its client's
 * place.
 *
 * @internal
 */
final readonly class TailShell implements Shell, StartsCommands
{
    /** The file the tail's workers' directory is named beside, among the adapter's own. */
    private const string BESIDE = 'pest/tail';

    /** The file the workers of controls started beside the survivors' runs again are named beside (see apart). */
    private const string APART = 'pest/tail-apart';

    /** The variables PHP and its extensions read as a process starts. */
    private const array STARTING = [MemoryCap::SCAN_DIR, Recorded::ENABLED, Xdebug::MODE_VARIABLE];

    private function __construct(
        private Shell $shell,
        private string $beside,
        private Project|NotGiven $booting,
        private OpcacheBlacklist|NotGiven $uncached,
        private Pool $pool,
    ) {
    }

    /**
     * The shell the tail of this request runs Pest in: this one, forking
     * where the request and the patch ask, and booting where `pest.bootTail`
     * does too.
     */
    public static function of(Shell $shell, Project $project, Patching $patching, MutationRequest $request): Shell
    {
        return self::named(self::BESIDE, $shell, $project, $patching, $request);
    }

    /**
     * The same, its workers named apart from those of any run of the tail the
     * survivors' runs again start while it runs (see ControlsSideBySide).
     */
    public static function apart(Shell $shell, Project $project, Patching $patching, MutationRequest $request): Shell
    {
        return self::named(self::APART, $shell, $project, $patching, $request);
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($command, $watch);
    }

    /**
     * Each command run through the client of its slot's worker: a booted
     * one, of the suites it names, where it runs every test file of them and
     * the tail boots, and an unbooted one otherwise. Each set of workers is
     * started for the runs, no more of them than there are runs where the
     * pool is sized by its work, in a directory with nothing an earlier run
     * left, and ended once they are done.
     */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        [$sets, $forked] = $this->forking($slots, ...$commands);
        $ends = $this->shell->sideBySide($slots, $startingWithin, ...$forked);
        $sets->cleared();

        return $ends;
    }

    /**
     * The same commands, started now where the shell starts them, their workers ended once they are awaited or stopped.
     */
    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): StartedProcesses {
        [$sets, $forked] = $this->forking($slots, ...$commands);
        $started = $this->shell instanceof StartsCommands
            ? $this->shell->started($slots, $startingWithin, ...$forked)
            : EndedProcesses::of($this->shell->sideBySide($slots, $startingWithin, ...$forked));

        return TailStarted::of($started, $sets);
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory), $this->beside, $this->booting, $this->uncached, $this->pool);
    }

    private static function named(
        string $name,
        Shell $shell,
        Project $project,
        Patching $patching,
        MutationRequest $request,
    ): Shell {
        $beside = $patching->isOn() && $request->pool()->workers()->forksTheTail()
            ? $project->fresh($name)
            : '';

        return is_string($beside) && $beside !== ''
            ? new self(
                $shell,
                $beside,
                $patching->bootsTail() ? $project : NotGiven::value(),
                $patching->cachesInWorkers() ? OpcacheBlacklist::of($project, $request) : NotGiven::value(),
                $request->pool(),
            )
            : $shell;
    }

    /**
     * The workers these commands fork from, and each command run through the
     * client of its slot's worker: a booted one, of the suites it names, where
     * it runs every test file of them and the tail boots, and an unbooted one
     * otherwise; no more workers than there are runs where the pool is sized
     * by its work.
     *
     * @return array{TailWorkers, list<Command>}
     */
    private function forking(WorkerSlots $slots, Command ...$commands): array
    {
        $booted = $slots->atMost($this->pool->slotsFor(ProcessCount::atLeastOne(count($commands))));
        $sets = new TailWorkers(
            $booted,
            $this->beside,
            $this->booting,
            $this->uncached,
            $this->pool->pace()->workerWait(),
        );
        $forked = [];

        foreach ($commands as $command) {
            $beside = $sets->besideFor($this->bootedSuites($command), $this->startingWith($command));
            $forked[] = $command->forkedIn(WarmDirectory::beside($beside));
        }

        return [$sets, $forked];
    }

    /**
     * What a command sets of the variables PHP reads as it starts, which the
     * workers it forks from must start with, as a fresh process of it would:
     * the directories of ini files it scans, a memory cap's among them, and
     * the coverage drivers' (ADR-0147).
     *
     * @return array<string, string>
     */
    private function startingWith(Command $command): array
    {
        $environment = $command->environment();
        $starting = [];

        foreach (self::STARTING as $name) {
            $value = array_key_exists($name, $environment) ? $environment[$name] : false;
            $starting += is_string($value) ? [$name => $value] : [];
        }

        return $starting;
    }

    /**
     * The suites a booted worker forks this command from, where the tail
     * boots and it runs every test file of the suites it names, none naming
     * every suite; or none, where an unbooted worker forks it.
     *
     * @return list<string>|NotGiven
     */
    private function bootedSuites(Command $command): array|NotGiven
    {
        return $this->booting instanceof Project && $command->loadsEveryTestFile()
            ? PhpUnitOption::suitesIn($command->arguments())
            : NotGiven::value();
    }
}
