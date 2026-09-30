<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function is_int;

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SearchPath;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function usleep;

/**
 * Runs a command as a process in one directory.
 *
 * - The process starts with the running PHP's directory first on the `PATH`,
 *   so the PHPUnit Infection starts for each mutant through its script's `#!`
 *   line runs on the same PHP as the gate.
 * - No inherited variable that makes PHPUnit or Infection act as a worker of
 *   another run, or makes a plugin act for the gate, reaches it unless the
 *   command sets it, and no variable the command withholds does, such as a
 *   credential of the CI or the proof store: the project's tests, and every
 *   mutant of its code, run in it.
 * - At its deadline it is stopped with every process it started.
 * - A process that cannot start, or fails while running, ends as a failure, with
 *   the reason as its output.
 */
final readonly class ProcessShell implements Shell
{
    /**
     * @param array<string, string> $inherited the environment the gate runs in
     * @param Clock                 $clock     what a deadline is measured on
     */
    public function __construct(
        private string $directory,
        private array $inherited,
        private Clock $clock = new WallClock(),
    ) {
    }

    public function in(string $directory): self
    {
        return new self($directory, $this->inherited, $this->clock);
    }

    public function run(Command $command): Ran
    {
        $process = new Process(
            $command->arguments(),
            $this->directory,
            [...$this->environment($command->withheld()), ...$command->environment()],
            timeout: null,
        );

        $started = $this->clock->nanoseconds();

        try {
            $process->start();
            $stopped = $this->stoppedAt($process, $command->deadline());
        } catch (ExceptionInterface $failure) {
            return Ran::finished(succeeded: false, output: $failure->getMessage());
        }

        $output = sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
        $ran = $stopped ? Ran::stopped($output) : Ran::finished(succeeded: $process->isSuccessful(), output: $output);

        return $ran->taking(Seconds::of(($this->clock->nanoseconds() - $started) / Seconds::NANOSECONDS));
    }

    /**
     * Each variable the process must not inherit as false, those that make a
     * process another run's worker whether or not the environment shows them,
     * and its `PATH`.
     *
     * @return array<string, string|false>
     */
    private function environment(Withheld $withheld): array
    {
        $environment = [
            ...array_filter(
                Withholding::of($withheld->and(Withheld::otherRuns()), $this->inherited),
                static fn(string|false $value): bool => $value === false,
            ),
            ...array_fill_keys(WorkerVariable::names(), value: false),
        ];
        $path = array_key_exists(SearchPath::VARIABLE, $this->inherited) ? $this->inherited[SearchPath::VARIABLE] : '';
        $environment[SearchPath::VARIABLE] = SearchPath::phpFirst($path);

        return $environment;
    }

    /** Waits for the process to end, or stops it with every process it started at its deadline, and says which. */
    private function stoppedAt(Process $process, Seconds|Unlimited $deadline): bool
    {
        if ($deadline instanceof Unlimited) {
            $process->wait();

            return false;
        }

        $end = $this->clock->nanoseconds() + $deadline->nanoseconds();

        while ($process->isRunning() && $this->clock->nanoseconds() < $end) {
            usleep(Polling::interval()->microseconds());
        }

        return $process->isRunning() && $this->stopped($process);
    }

    /** Stops a process, and every process it started first, so none is left running on its own. */
    private function stopped(Process $process): bool
    {
        $pid = $process->getPid();
        $listing = new Process(ProcessTable::LISTING);
        $listing->run();
        $started = is_int($pid) ? ProcessTable::parse($listing->getOutput())->descendantsOf($pid) : [];

        if ($started !== []) {
            new Process(ProcessTable::killing(...$started))->run();
        }

        $process->stop(0);

        return true;
    }
}
