<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\RunnerPace;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The runner a config chooses, the environment variables it adds to those
 * the runner withholds from the project's tests, the memory each of its
 * mutants' processes may use (ADR-0004), and how each of its mutants' runs
 * starts (ADR-0023).
 *
 * @internal
 */
final readonly class ChosenRunner
{
    private function __construct(
        private Choice $choice,
        private Withheld $withhold,
        private MemoryCap $memory,
        private Workers $workers,
        private RunnerPacing $pacing,
    ) {
    }

    public static function of(
        Choice $choice,
        Withheld $withhold,
        MemoryCap $memory,
        Workers $workers,
        RunnerPacing $pacing,
    ): self {
        return new self($choice, $withhold, $memory, $workers, $pacing);
    }

    public function choice(): Choice
    {
        return $this->choice;
    }

    /** The runner's name as the config chooses it: a registered name, or the class an extension's runner has. */
    public function name(): Name
    {
        $use = $this->choice->use();

        return $use instanceof Name ? $use : Name::of($use->value());
    }

    /**
     * `runner.withhold`: the environment variables, by name or glob, a project adds to those the runner never
     * hands its tests (ADR-0004). They only ever add to the ones every run withholds.
     */
    public function withhold(): Withheld
    {
        return $this->withhold;
    }

    /** `runner.memory`: the memory each process that runs a mutant may use (ADR-0004). */
    public function memory(): MemoryCap
    {
        return $this->memory;
    }

    /** `runner.workers`: how each mutant's run starts (ADR-0023). */
    public function workers(): Workers
    {
        return $this->workers;
    }

    /**
     * `runner.recycleAfter`, `runner.warmSlack` and `runner.workerWait`: how
     * the runner paces its work, a warm worker waited for as long as a run is
     * allowed to start in where no layer says.
     */
    public function pace(Seconds $startUp): RunnerPace
    {
        return $this->pacing->pace($startUp);
    }

    /** `runner.poll`: how long the gate waits between looks at a process it waits on. */
    public function poll(): Seconds
    {
        return $this->pacing->poll();
    }

    /** `runner.processes`: how many mutants the runner runs at once on a machine with these cores. */
    public function processes(ProcessCount $cores): ProcessCount
    {
        return $this->pacing->processes($cores);
    }
}
