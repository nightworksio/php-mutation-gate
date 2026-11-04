<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function count;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
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
 * nothing an earlier run left in their directory. Under `pest.bootTail`, a
 * run of every test file of its suites is run by a worker that booted the
 * project, those suites' test files loaded (ADR-0075). A run
 * whose worker forks it no child, or is gone, starts fresh in its client's
 * place.
 *
 * @internal
 */
final readonly class TailShell implements Shell
{
    /** The file the tail's workers' directory is named beside, among the adapter's own. */
    private const string BESIDE = 'pest/tail';

    /** The file the directory of the tail's booted workers of one set of suites is named beside, by its place. */
    private const string BOOTED = '%s-booted-%d';

    private function __construct(private Shell $shell, private string $beside, private Project|NotGiven $booting)
    {
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
            ? new self($shell, $beside, $patching->bootsTail() ? $project : NotGiven::value())
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
        $besides = [];
        $forked = [];

        foreach (array_values($commands) as $command) {
            $suites = $this->bootedSuites($command);
            $key = $suites instanceof NotGiven ? '' : implode(',', $suites);
            $besides[$key] ??= $this->started($slots, count($besides), $suites);
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
        return new self($this->shell->in($directory), $this->beside, $this->booting);
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

    /**
     * A set of workers started, one in each slot, in a directory of its own
     * by its place among the sets, booting these suites where it boots:
     * what that directory is named beside.
     *
     * @param list<string>|NotGiven $suites
     */
    private function started(WorkerSlots $slots, int $place, array|NotGiven $suites): string
    {
        $beside = $suites instanceof NotGiven ? $this->beside : sprintf(self::BOOTED, $this->beside, $place);
        WarmDirectory::cleared($beside);
        $directory = WarmDirectory::made($beside);

        if ($this->booting instanceof Project && ! $suites instanceof NotGiven) {
            Invocation::booting($this->booting, $suites)->leftIn($directory);
        }

        foreach (array_keys([...$slots]) as $at) {
            Pool::started(Seat::at($directory, $at + 1));
        }

        return $beside;
    }
}
