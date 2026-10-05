<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * One test file a change can make fail: the coverage map's tests in it that
 * the change reaches, and every reason it is listed.
 */
final readonly class AffectedTest
{
    private function __construct(private Path $file, private TestIds $tests, private Reasons $reasons)
    {
    }

    public static function of(Path $file, TestIds $tests, Reasons $reasons): self
    {
        return new self($file, $tests, $reasons);
    }

    /** This test file, reached by more of its tests, for one more reason. */
    public function and(TestIds $tests, Reason $reason): self
    {
        $all = $this->tests;

        foreach ($tests as $test) {
            $all = $all->with($test);
        }

        return new self($this->file, $all, $this->reasons->with($reason));
    }

    /** This test file, with the tests and the reasons another listing of it has besides. */
    public function with(self $other): self
    {
        $tests = $this->tests;
        $reasons = $this->reasons;

        foreach ($other->tests as $test) {
            $tests = $tests->with($test);
        }

        foreach ($other->reasons as $reason) {
            $reasons = $reasons->with($reason);
        }

        return new self($this->file, $tests, $reasons);
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** The map's tests of the file the change reaches. */
    public function tests(): TestIds
    {
        return $this->tests;
    }

    public function reasons(): Reasons
    {
        return $this->reasons;
    }
}
