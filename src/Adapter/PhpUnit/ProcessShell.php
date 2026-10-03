<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_fill_keys;
use function array_key_exists;
use function array_map;
use function hrtime;
use function is_int;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
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
 * Runs a command as a process in one directory, with the running PHP's
 * directory first on the `PATH`, and says how long it took.
 *
 * - No inherited variable that makes a process a worker or a mutant of
 *   another run reaches it, nor any the command withholds, such as a
 *   credential of the CI: the project's tests, and every mutant of its code,
 *   run in it. What the command sets, it gets.
 * - Where the command scans a memory cap's directory, its PHP processes scan
 *   it after the directories the inherited `PHP_INI_SCAN_DIR` names, or
 *   PHP's own where it names none (ADR-0004, decision 9).
 * - At its deadline it is stopped, and so is every process it started.
 * - A process that cannot start, or fails while running, ends as a failure,
 *   with the reason as its output.
 */
final readonly class ProcessShell implements Shell
{
    /** @param array<string, string> $inherited the environment the gate runs in */
    public function __construct(private string $directory, private array $inherited)
    {
    }

    public function in(string $directory): self
    {
        return new self($directory, $this->inherited);
    }

    public function run(Command $command): Ran
    {
        $process = new Process(
            $command->arguments(),
            $this->directory,
            [
                ...$this->scrubbed($command->withheld()),
                ...$this->unset(),
                ...$command->environment(),
                ...$this->capped($command),
            ],
            timeout: null,
        );

        $started = hrtime(as_number: true);

        try {
            $process->start();
            $stopped = ! $this->endsBy($process, $command->deadline());
        } catch (ExceptionInterface $failure) {
            return Ran::finished(succeeded: false, output: $failure->getMessage())->took($this->since($started));
        }

        $said = sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
        $ran = $stopped ? Ran::stopped($said) : Ran::exited($process->getExitCode() ?? NotGiven::value(), $said);

        return $ran->took($this->since($started));
    }

    /**
     * Every variable the process inherits, each it must not see as false, and
     * the `PATH` it starts with.
     *
     * @return array<string, string|false>
     */
    private function scrubbed(Withheld $withheld): array
    {
        $environment = Withholding::of($withheld->and(Withheld::otherRuns()), $this->inherited);
        $inherited = array_key_exists(SearchPath::VARIABLE, $this->inherited)
            ? $this->inherited[SearchPath::VARIABLE]
            : '';
        $environment[SearchPath::VARIABLE] = SearchPath::phpFirst($inherited);

        return $environment;
    }

    /**
     * The variables the gate sets for its extension and its wrapper, and
     * those that make a process another run's worker, each unset whether or
     * not the environment holds it, since a process also inherits what
     * `$_ENV` holds, which `getenv()` does not show. Only the command that
     * needs one sets it.
     *
     * @return array<string, false>
     */
    private function unset(): array
    {
        $names = array_map(static fn(Variable $variable): string => $variable->value, Variable::cases());

        return array_fill_keys([...$names, ...WorkerVariable::names()], value: false);
    }

    /** @return array<string, string> the directories PHP scans for ini files, where the command is capped */
    private function capped(Command $command): array
    {
        $directory = $command->scanned();
        $inherited = array_key_exists(MemoryCap::SCAN_DIR, $this->inherited)
            ? $this->inherited[MemoryCap::SCAN_DIR]
            : false;

        return $directory instanceof DiskPath
            ? [MemoryCap::SCAN_DIR => MemoryCap::scanning($inherited, $directory->value())]
            : [];
    }

    private function since(int|float $started): Seconds
    {
        return Seconds::of((hrtime(as_number: true) - $started) / Seconds::NANOSECONDS);
    }

    /** Whether the process ends by its deadline; one that does not is stopped with every process it started. */
    private function endsBy(Process $process, Seconds|Unlimited $deadline): bool
    {
        if ($deadline instanceof Unlimited) {
            $process->wait();

            return true;
        }

        $by = hrtime(as_number: true) + $deadline->nanoseconds();

        while ($process->isRunning() && hrtime(as_number: true) < $by) {
            usleep(Polling::interval()->microseconds());
        }

        $running = $process->isRunning();

        if ($running) {
            $this->stopWithDescendants($process);
        }

        return ! $running;
    }

    private function stopWithDescendants(Process $process): void
    {
        $pid = $process->getPid();
        $listing = new Process(ProcessTable::LISTING);
        $listing->run();
        $descendants = is_int($pid) ? ProcessTable::parse($listing->getOutput())->descendantsOf($pid) : [];

        if ($descendants !== []) {
            new Process(ProcessTable::killing(...$descendants))->run();
        }

        $process->stop(0);
    }
}
