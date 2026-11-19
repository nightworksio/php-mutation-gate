<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How one process the gate starts is held (ADR-0158): as the leader of a
 * session of its own and in a cgroup of its own, each where the gate's own
 * process was told it holds its processes so; and how what it left is let go
 * once it ends or is stopped.
 *
 * @internal
 */
final readonly class Holding
{
    private function __construct(private bool $leads, private RunCgroup|NotGiven $cgroup)
    {
    }

    /** The hold of a process about to start, as this process was told. */
    public static function told(): self
    {
        return new self(SessionLeader::leads(), RunCgroup::made());
    }

    /**
     * What starts these arguments held so.
     *
     * @param  list<string> $arguments
     * @return list<string>
     */
    public function command(array $arguments): array
    {
        $led = [...SessionLeader::prefix(), ...$arguments];

        return $this->cgroup instanceof RunCgroup ? [...$this->cgroup->joining(), ...$led] : $led;
    }

    /** What it left once it ended killed: the session it led, and its cgroup, which is removed. */
    public function ended(int $pid): void
    {
        if ($this->leads) {
            ProcessTree::of($pid)->ended();
        }

        $this->uncontained();
    }

    /** It stopped with every process under it and in the session it leads, and its cgroup killed and removed. */
    public function stopped(int $pid): void
    {
        ProcessTree::of($pid)->stop($this->leads);
        $this->uncontained();
    }

    /** Its cgroup let go, for a process that never started. */
    public function unstarted(): void
    {
        $this->uncontained();
    }

    private function uncontained(): void
    {
        if ($this->cgroup instanceof RunCgroup) {
            $this->cgroup->killed();
        }
    }
}
