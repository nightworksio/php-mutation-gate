<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use Symfony\Component\Process\Process;

/**
 * A running process and every process under it, at any depth, as `ps` lists
 * them, and the session it leads where it leads one.
 *
 * @internal
 */
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

    /**
     * Stops every process under this one, deepest first, and then the
     * process itself, with the session it leads where it leads one (see
     * ProcessTable).
     */
    public function stop(bool $leads): void
    {
        ProcessTable::stop($this->pid, $this->ran(...), $leads, Lineage::spared());
    }

    /** Kills every process left in the session this one led, once it ended. */
    public function ended(): void
    {
        ProcessTable::end($this->pid, $this->ran(...), Lineage::spared());
    }

    /** @param list<string> $command */
    private function ran(array $command): string
    {
        $process = new Process($command);
        $process->run();

        return $process->getOutput();
    }
}
