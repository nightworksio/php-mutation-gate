<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * A hold written only in the suites `tests.holding` lists (ADR-0005):
 * the path it holds, the tests that hold it there, and the declaration
 * as its author wrote it. It adds those tests to the judges of the
 * path's lines they run, and narrows nothing.
 *
 * @internal
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
        return $this->isHeldBy($test) && $file->within($this->path);
    }

    /** Whether this test holds the path. */
    public function isHeldBy(TestId $test): bool
    {
        return $this->tests->has($test);
    }

    /** Whether one of its tests runs a line of what it holds, by an outline of a map. */
    public function runsIn(MapOutline $outline): bool
    {
        foreach ($this->tests as $test) {
            foreach ($outline->ran($test) as $file) {
                if ($file->within($this->path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The declaration as its author wrote it, to name it in a message. */
    public function written(): string
    {
        return $this->written;
    }
}
