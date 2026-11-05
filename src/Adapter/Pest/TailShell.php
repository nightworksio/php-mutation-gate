<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * The shell the verification tail runs Pest in where `runner.workers` forks
 * it under `pest.patch` (ADR-0070): the runs it starts side by side, the
 * unmutated controls, baselines and trials, each run by the warm worker of
 * its place, which the gate's own process starts for the runs and ends once
 * they are done, as Pest's parent does for a mutation run (ADR-0023), with
 * nothing an earlier run left in their directory. The workers start PHP
 * with the ini files the runs scan, so a memory cap reaches a forked run as
 * it reaches a fresh one; runs that scan others fork from workers of their
 * own. A run
 * whose worker forks it no child, or is gone, starts fresh in its client's
 * place.
 *
 * @internal
 */
final readonly class TailShell implements Shell
{
    /** The file the tail's workers' directory is named beside, among the adapter's own. */
    private const string BESIDE = 'pest/tail';

    /** The file the directory of a set of workers that start PHP otherwise is named beside, by its place. */
    private const string STARTING = '%s-%d';

    private function __construct(private Shell $shell, private string $beside)
    {
    }

    /** The shell the tail of this request runs Pest in: this one, forking where the request and the patch ask. */
    public static function of(Shell $shell, Project $project, Patching $patching, MutationRequest $request): Shell
    {
        $beside = $patching->isOn() && $request->pool()->workers()->forksTheTail()
            ? $project->fresh(self::BESIDE)
            : '';

        return is_string($beside) && $beside !== '' ? new self($shell, $beside) : $shell;
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($command, $watch);
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $besides = [];
        $forked = [];

        foreach ($commands as $command) {
            $startingWith = $this->startingWith($command);
            $key = implode("\n", $startingWith);
            $besides[$key] ??= $this->started(
                $slots,
                $besides === [] ? $this->beside : sprintf(self::STARTING, $this->beside, count($besides) + 1),
                $startingWith,
            );
            $forked[] = $command->forkedIn(WarmDirectory::beside($besides[$key]));
        }

        $ends = $this->shell->sideBySide($slots, $startingWithin, ...$forked);

        foreach ($besides as $beside) {
            WarmDirectory::cleared($beside);
        }

        return $ends;
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory), $this->beside);
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
        $environment = $command->environment();
        $scanned = array_key_exists(MemoryCap::SCAN_DIR, $environment) ? $environment[MemoryCap::SCAN_DIR] : false;

        return is_string($scanned) ? [MemoryCap::SCAN_DIR => $scanned] : [];
    }

    /**
     * A set of workers started, one in each slot, with these variables, in
     * the directory named beside this, with nothing an earlier run left:
     * what it is named beside.
     *
     * @param array<string, string> $startingWith
     */
    private function started(WorkerSlots $slots, string $beside, array $startingWith): string
    {
        WarmDirectory::cleared($beside);
        $directory = WarmDirectory::made($beside);

        foreach (array_keys([...$slots]) as $at) {
            Pool::started(Seat::at($directory, $at + 1), $startingWith);
        }

        return $beside;
    }
}
