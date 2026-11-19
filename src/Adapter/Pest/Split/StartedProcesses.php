<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use Closure;

use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\ServedChildren;

use function putenv;
use function sprintf;
use function unlink;

/**
 * The PHP processes a split stream's parent's current test started (ADR-0150):
 * where the run serves its processes their mutant, each marks that it ran,
 * in a file beside the results. Each ran without the mutant of any child the
 * test forks after it, or of any mutant whose site it ran itself, so such a
 * test forks no more once one ran, and its mutants are judged by their own
 * runs.
 *
 * @internal
 */
final readonly class StartedProcesses
{
    private const string MARKED = '%s.started';

    private function __construct(private string $marked)
    {
    }

    /** The processes of the stream writing these results. */
    public static function beside(string $results): self
    {
        return new self(sprintf(self::MARKED, $results));
    }

    /** A test of the parent begins: none of its processes ran yet, and each it starts marks that it did. */
    public function watched(): void
    {
        $this->unmarked();
        putenv(sprintf('%s=%s', ServedChildren::SPAWNED, $this->marked));
    }

    /** Whether a process the parent's current test started ran. */
    public function ran(): bool
    {
        return is_file($this->marked);
    }

    /**
     * This run where a process the parent's current test started ran.
     *
     * @param Closure(): void $then
     */
    public function whereRan(Closure $then): void
    {
        if ($this->ran()) {
            $then();
        }
    }

    /** In a child, whose processes are served its mutant, and as the stream ends: no process marks anything. */
    public function unwatched(): void
    {
        $this->unmarked();
        putenv(ServedChildren::SPAWNED);
    }

    private function unmarked(): void
    {
        if (is_file($this->marked)) {
            unlink($this->marked);
        }
    }
}
