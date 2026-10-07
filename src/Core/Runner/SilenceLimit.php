<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How long a process may go without progress before it is stopped with
 * every process it started, and the file whose growth is its progress, such
 * as a mutant's results file a line is added to as each test starts and
 * ends. The silence is counted from the file's last growth, so what the
 * process does before the file first grows is held only to its deadline
 * (ADR-0008, decision 2).
 */
final readonly class SilenceLimit
{
    private function __construct(private Seconds $limit, private string $progress)
    {
    }

    public static function of(Seconds $limit, string $progress): self
    {
        return new self($limit, $progress);
    }

    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** The file whose growth is the process's progress. */
    public function progress(): string
    {
        return $this->progress;
    }
}
