<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function is_int;

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use Symfony\Component\Process\Process;

/** A running process and every process under it, at any depth, as `ps` lists them. */
final readonly class ProcessTree
{
    private function __construct(private Process $process)
    {
    }

    public static function of(Process $process): self
    {
        return new self($process);
    }

    /** Stops every process under this one, deepest first, and then the process itself. */
    public function stop(): void
    {
        $pid = $this->process->getPid();
        $listing = new Process(ProcessTable::LISTING);
        $listing->run();
        $under = is_int($pid) ? ProcessTable::parse($listing->getOutput())->descendantsOf($pid) : [];

        if ($under !== []) {
            new Process(ProcessTable::killing(...$under))->run();
        }

        $this->process->stop(0);
    }
}
