<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

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
    /** How long it waits between looks at a running process, in seconds. */
    private const float POLL = 0.05;

    /** A shell running each command in a directory, its deadline measured on this clock. */
    public function __construct(private string $directory, private Clock $clock = new WallClock())
    {
    }

    public function in(string $directory): self
    {
        return new self($directory, $this->clock);
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
        $until = $deadline instanceof Seconds ? $this->clock->seconds() + $deadline->seconds() : INF;

        while ($process->isRunning()) {
            if ($this->clock->seconds() >= $until) {
                ProcessTree::of($process)->stop();

                return Ran::stopped($this->outputOf($process));
            }

            usleep(Seconds::of(self::POLL)->microseconds());
        }

        return Ran::finished(succeeded: $process->isSuccessful(), output: $this->outputOf($process));
    }

    private function outputOf(Process $process): string
    {
        return sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
    }
}
