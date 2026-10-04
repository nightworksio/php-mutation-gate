<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;

/**
 * How many tests a mutant's own process ran, counted as each finishes and
 * written once, as a `ran` line, when PHPUnit ends the run, which it does
 * whether or not its filter selected a test (ADR-0004, decision 9). A
 * process that never ends its run, as one stopped or crashed does, writes
 * no count.
 */
final class RanTests
{
    private int $finished = 0;

    public function __construct(private readonly string $results, private readonly string $mutated)
    {
    }

    /** Counts a test that finished, whatever its outcome. */
    public function counted(): void
    {
        $this->finished++;
    }

    /** Writes how many tests the run ran. */
    public function written(): void
    {
        file_put_contents($this->results, RecordLine::ran($this->mutated, $this->finished), FILE_APPEND | LOCK_EX);
    }
}
