<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** One mutant's trial: the tests that judge it, the file it mutates, its mutated copy, and how long a run may take. */
final readonly class TrialRun
{
    private function __construct(
        private Paths $tests,
        private Path $original,
        private string $copy,
        private Seconds $limit,
    ) {
    }

    public static function of(Paths $tests, Path $original, string $copy, Seconds $limit): self
    {
        return new self($tests, $original, $copy, $limit);
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

    public function original(): Path
    {
        return $this->original;
    }

    public function copy(): string
    {
        return $this->copy;
    }

    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** The set of tests, as one text: trials of the same set share their run on their own. */
    public function set(): string
    {
        $values = [];

        foreach ($this->tests as $test) {
            $values[] = $test->value();
        }

        return implode("\n", $values);
    }
}
