<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Runs a command as a process in one directory, and stops it at its deadline. */
final readonly class ProcessShell implements Shell
{
    public function __construct(private string $directory)
    {
    }

    public function run(Command $command): Ran
    {
        $deadline = $command->deadline();
        $process = new Process(
            $command->arguments(),
            $this->directory,
            $command->environment(),
            timeout: $deadline instanceof Seconds ? $deadline->seconds() : null,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return Ran::stopped($this->outputOf($process));
        }

        return Ran::finished(succeeded: $process->isSuccessful(), output: $this->outputOf($process));
    }

    private function outputOf(Process $process): string
    {
        return sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
    }
}
