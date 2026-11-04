<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_map;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
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
 * nothing an earlier run left in their directory. A run
 * whose worker forks it no child, or is gone, starts fresh in its client's
 * place.
 *
 * @internal
 */
final readonly class TailShell implements Shell
{
    /** The file the tail's workers' directory is named beside, among the adapter's own. */
    private const string BESIDE = 'pest/tail';

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
        WarmDirectory::cleared($this->beside);
        $directory = WarmDirectory::made($this->beside);

        foreach (array_keys([...$slots]) as $at) {
            Pool::started(Seat::at($directory, $at + 1));
        }

        $ends = $this->shell->sideBySide(
            $slots,
            $startingWithin,
            ...array_map(static fn(Command $command): Command => $command->forkedIn($directory), $commands),
        );
        WarmDirectory::cleared($this->beside);

        return $ends;
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory), $this->beside);
    }
}
