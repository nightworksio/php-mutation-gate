<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_any;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function dirname;
use function is_int;

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;

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
    private const string PATH = 'PATH';

    /** The prefixes of the inherited variables that make a process another run's worker, which are removed. */
    private const array WITHHELD = [
        'INFECTION_',
        'MUTATION_GATE_',
        'PEST_MUTATION_',
        'PARATEST',
        'TEST_TOKEN',
        'UNIQUE_TEST_TOKEN',
    ];

    /** How long the shell waits between looks at a running process, in microseconds. */
    private const int POLL = 20000;

    /** Each process and the one that started it, as `ps` lists them. */
    private const string PARENTS = '/^\s*(?<pid>\d+)\s+(?<parent>\d+)\s*$/m';

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

    /** @return array<string, string|false> each variable the process gets, or false for one it must not inherit */
    private function environment(Withheld $withheld): array
    {
        $environment = [];

        foreach (array_keys($this->inherited) as $name) {
            if (
                preg_match($withheld->pattern(), $name) === 1
                || array_any(self::WITHHELD, static fn(string $prefix): bool => str_starts_with($name, $prefix))
            ) {
                $environment[$name] = false;
            }
        }

        $path = array_key_exists(self::PATH, $this->inherited) ? $this->inherited[self::PATH] : '';
        $environment[self::PATH] = sprintf('%s%s%s', dirname(PHP_BINARY), PATH_SEPARATOR, $path);

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
            usleep(self::POLL);
        }

        return $process->isRunning() && $this->stopped($process);
    }

    /** Stops a process, and every process it started first, so none is left running on its own. */
    private function stopped(Process $process): bool
    {
        $pid = $process->getPid();
        $started = is_int($pid) ? $this->startedBy($pid) : [];

        if ($started !== []) {
            new Process(['kill', '-KILL', ...array_map(static fn(int $each): string => sprintf('%d', $each), $started)])
                ->run();
        }

        $process->stop(0);

        return true;
    }

    /**
     * Every process a process started, and every process those started, as `ps` lists them.
     *
     * @return list<int>
     */
    private function startedBy(int $pid): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        $listing->run();
        preg_match_all(self::PARENTS, $listing->getOutput(), $rows);
        $children = [];

        foreach ($rows['pid'] as $index => $child) {
            $children[(int) $rows['parent'][$index]][] = (int) $child;
        }

        $found = [];
        $waiting = [$pid];

        while ($waiting !== []) {
            $parent = array_shift($waiting);
            $mine = array_key_exists($parent, $children) ? $children[$parent] : [];
            $found = [...$found, ...$mine];
            $waiting = [...$waiting, ...$mine];
        }

        return $found;
    }
}
