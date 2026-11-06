<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
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
final readonly class TailShell implements Shell
{
    /** The file the tail's workers' directory is named beside, among the adapter's own. */
    private const string BESIDE = 'pest/tail';

    private function __construct(
        private Shell $shell,
        private string $beside,
        private Project|NotGiven $booting,
        private OpcacheBlacklist|NotGiven $uncached,
    ) {
    }

    /**
     * The shell the tail of this request runs Pest in: this one, forking
     * where the request and the patch ask, and booting where `pest.bootTail`
     * does too.
     */
    public static function of(Shell $shell, Project $project, Patching $patching, MutationRequest $request): Shell
    {
        $beside = $patching->isOn() && $request->pool()->workers()->forksTheTail()
            ? $project->fresh(self::BESIDE)
            : '';

        return is_string($beside) && $beside !== ''
            ? new self(
                $shell,
                $beside,
                $patching->bootsTail() ? $project : NotGiven::value(),
                $patching->cachesInWorkers() ? OpcacheBlacklist::of($project, $request) : NotGiven::value(),
            )
            : $shell;
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($command, $watch);
    }

    /**
     * Each command run through the client of its slot's worker: a booted
     * one, of the suites it names, where it runs every test file of them and
     * the tail boots, and an unbooted one otherwise. Each set of workers is
     * started for the runs, in a directory with nothing an earlier run left,
     * and ended once they are done.
     */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $sets = new TailWorkers($slots, $this->beside, $this->booting, $this->uncached);
        $forked = [];

        foreach ($commands as $command) {
            $beside = $sets->besideFor($this->bootedSuites($command), $this->startingWith($command));
            $forked[] = $command->forkedIn(WarmDirectory::beside($beside));
        }

        $ends = $this->shell->sideBySide($slots, $startingWithin, ...$forked);
        $sets->cleared();

        return $ends;
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory), $this->beside, $this->booting, $this->uncached);
    }

    /**
     * What a command sets of the variables PHP reads as it starts, which the
     * workers it forks from must start with, as a fresh process of it would:
     * the directories of ini files it scans, a memory cap's among them.
     *
     * @return array<string, string>
     */
    private function startingWith(Command $command): array
    {
        foreach ($command->environment() as $name => $value) {
            if ($name === MemoryCap::SCAN_DIR && is_string($value)) {
                return [$name => $value];
            }
        }

        return [];
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
