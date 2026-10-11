<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * A hold written only in the suites `tests.holding` lists (ADR-0005,
 * decision 9): the path it holds, the tests that hold it there, and the
 * declaration as its author wrote it. It adds those tests to the judges of
 * the path's lines they run, and narrows nothing.
 */
final readonly class Addition
{
    private function __construct(private Path $path, private TestIds $tests, private string $written)
    {
    }

    public static function of(Path $path, TestIds $tests, string $written): self
    {
        return new self($path, $tests, $written);
    }

    public function path(): Path
    {
        return $this->path;
    }

    /** Whether this test holds the path, on a line of this file. */
    public function adds(TestId $test, Path $file): bool
    {
        return $file->within($this->path) && $this->tests->has($test);
    }

    /** The declaration as its author wrote it, to name it in a message. */
    public function written(): string
    {
        return $this->written;
    }
}
