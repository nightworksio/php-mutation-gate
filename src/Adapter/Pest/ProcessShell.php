<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function explode;
use function microtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function preg_match;
use function sprintf;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function usleep;

/**
 * Runs a command as a process in one directory. At its deadline it stops the
 * process and every process under it, such as Pest's paratest workers and
 * each mutant's own run, so none is left running after the gate moves on. A
 * program that cannot be started did not succeed, and says why.
 */
final readonly class ProcessShell implements Shell
{
    /** How long it waits between looks at a running process, in microseconds. */
    private const int POLL = 50_000;

    /** A line of `ps`: a process and its parent. */
    private const string PROCESS = '~^\s*(?<pid>\d+)\s+(?<parent>\d+)\s*$~';

    public function __construct(private string $directory)
    {
    }

    public function in(string $directory): self
    {
        return new self($directory);
    }

    public function run(Command $command): Ran
    {
        $process = new Process($command->arguments(), $this->directory, $command->environment(), timeout: null);

        try {
            $process->start();
        } catch (RuntimeException $failure) {
            return Ran::finished(succeeded: false, output: $failure->getMessage());
        }

        return $this->awaited($process, $command->deadline());
    }

    private function awaited(Process $process, Seconds|Unlimited $deadline): Ran
    {
        $until = $deadline instanceof Seconds ? microtime(as_float: true) + $deadline->seconds() : INF;

        while ($process->isRunning()) {
            if (microtime(as_float: true) >= $until) {
                $this->stopped($process);

                return Ran::stopped($this->outputOf($process));
            }

            usleep(self::POLL);
        }

        return Ran::finished(succeeded: $process->isSuccessful(), output: $this->outputOf($process));
    }

    /** Stops every process under this one, deepest first, and then the process itself. */
    private function stopped(Process $process): void
    {
        $under = $this->under((int) $process->getPid(), $this->parents());

        if ($under !== []) {
            new Process(['kill', '-KILL', ...array_map(static fn(int $pid): string => sprintf('%d', $pid), $under)])
                ->run();
        }

        $process->stop(0);
    }

    /**
     * Every process's parent, by process id, as `ps` lists them.
     *
     * @return array<int, int>
     */
    private function parents(): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        $listing->run();
        $parents = [];

        foreach (explode("\n", $listing->getOutput()) as $line) {
            if (preg_match(self::PROCESS, $line, $found) === 1) {
                $parents[(int) $found['pid']] = (int) $found['parent'];
            }
        }

        return $parents;
    }

    /**
     * The processes under one, at any depth, deepest first.
     *
     * @param array<int, int> $parents
     * @return list<int>
     */
    private function under(int $pid, array $parents): array
    {
        $under = [];

        foreach ($parents as $child => $parent) {
            $under = $parent === $pid ? [...$under, ...$this->under($child, $parents), $child] : $under;
        }

        return $under;
    }

    private function outputOf(Process $process): string
    {
        return sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
    }
}
