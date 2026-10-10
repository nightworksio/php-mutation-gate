<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use Symfony\Component\Process\Process;

/** A running process and every process under it, at any depth, as `ps` lists them. */
final readonly class ProcessTree
{
    private function __construct(private int $pid)
    {
    }

    /** The tree under the process with this id. */
    public static function of(int $pid): self
    {
        return new self($pid);
    }

    /** Stops every process under this one, deepest first, and then the process itself. */
    public function stop(): void
    {
        $listing = new Process(ProcessTable::LISTING);
        $listing->run();
        $under = ProcessTable::parse($listing->getOutput())->descendantsOf($this->pid);
        new Process(ProcessTable::killing(...[...$under, $this->pid]))->run();
    }
}
