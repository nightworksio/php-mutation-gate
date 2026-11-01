<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function file_put_contents;

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\ListedTests;

/**
 * Every test of the suites Testo runs and the groups each is in, in the
 * order Testo reaches them, written once the run is done (see ListedTests).
 * The run selects no test, so none runs.
 */
final class Listing
{
    /** @var list<Candidate> */
    private array $tests = [];

    public function __construct(private readonly string $file)
    {
    }

    public function add(Candidate $test): void
    {
        $this->tests[] = $test;
    }

    public function written(): void
    {
        file_put_contents($this->file, ListedTests::encoded(...$this->tests));
    }
}
