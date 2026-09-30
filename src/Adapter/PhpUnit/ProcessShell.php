<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function dirname;
use function hrtime;
use function is_int;

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function preg_match;
use function preg_match_all;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function usleep;

/**
 * Runs a command as a process in one directory, with the running PHP's
 * directory first on the `PATH`.
 *
 * - No inherited variable that makes a process a worker or a mutant of
 *   another run reaches it, nor any the command withholds, such as a
 *   credential of the CI: the project's tests, and every mutant of its code,
 *   run in it. What the command sets, it gets.
 * - At its deadline it is stopped, and so is every process it started.
 * - A process that cannot start, or fails while running, ends as a failure,
 *   with the reason as its output.
 */
final readonly class ProcessShell implements Shell
{
    /** How long the shell sleeps between looks at a running process, in microseconds. */
    private const int NAP = 10000;

    /** A process and its parent, as `ps -o pid= -o ppid=` lists them. */
    private const string CHILD_OF = '/^\s*(?<child>\d+)\s+(?<parent>\d+)\s*$/m';

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
            array_merge($this->scrubbed($command->withheld()), $command->environment()),
            timeout: null,
        );

        $started = hrtime(as_number: true);

        try {
            $process->start();
            $stopped = ! $this->endsBy($process, $command->deadline());
        } catch (ExceptionInterface $failure) {
            return Ran::finished(succeeded: false, output: $failure->getMessage(), took: $this->since($started));
        }

        $said = sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
        $took = $this->since($started);

        return $stopped
            ? Ran::stopped($said, $took)
            : Ran::finished(succeeded: $process->isSuccessful(), output: $said, took: $took);
    }

    /**
     * The inherited variables the process must not see, each set to false,
     * and the `PATH` it starts with.
     *
     * @return array<string, string|false>
     */
    private function scrubbed(Withheld $withheld): array
    {
        $never = $withheld->and($this->otherRuns())->pattern();
        $scrubbed = [];

        foreach (array_keys($this->inherited) as $name) {
            if (preg_match($never, $name) === 1) {
                $scrubbed[$name] = false;
            }
        }

        $path = 'PATH';
        $inherited = array_key_exists($path, $this->inherited) ? $this->inherited[$path] : '';
        $scrubbed[$path] = sprintf('%s%s%s', dirname(PHP_BINARY), PATH_SEPARATOR, $inherited);

        return $scrubbed;
    }

    private function since(int|float $started): Seconds
    {
        return Seconds::of((hrtime(as_number: true) - $started) / Seconds::NANOSECONDS);
    }

    /** The variables that make a process a worker or a mutant of another run. */
    private function otherRuns(): Withheld
    {
        return Withheld::of('MUTATION_GATE_*', 'INFECTION_*', 'PEST_MUTATION_*', 'PARATEST', '*TEST_TOKEN');
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
            usleep(self::NAP);
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
        $descendants = is_int($pid) ? $this->descendantsOf($pid) : [];

        if ($descendants !== []) {
            new Process(['kill', '-KILL', ...$descendants])->run();
        }

        $process->stop(0);
    }

    /**
     * Every process a process started, and those they started, by their ids.
     *
     * @return list<string>
     */
    private function descendantsOf(int $pid): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        $listing->run();
        preg_match_all(self::CHILD_OF, $listing->getOutput(), $rows);
        $descendants = [];
        $parents = [sprintf('%d', $pid) => true];
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($rows['child'] as $row => $child) {
                if (array_key_exists($rows['parent'][$row], $parents) && ! array_key_exists($child, $parents)) {
                    $parents[$child] = true;
                    $descendants[] = $child;
                    $grew = true;
                }
            }
        }

        return $descendants;
    }
}
