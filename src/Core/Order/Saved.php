<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What a quick round of `watch` takes first (ADR-0146): the files saved since
 * the round before, and the files the tests of the saved test files run.
 */
final readonly class Saved
{
    private function __construct(private Paths $edited, private Paths $retested)
    {
    }

    /** Nothing saved, as in every run that is not a quick round. */
    public static function none(): self
    {
        return new self(Paths::none(), Paths::none());
    }

    /** These files saved, and these files run by the tests of the saved test files. */
    public static function of(Paths $edited, Paths $retested): self
    {
        return new self($edited, $retested);
    }

    /** Whether a file was saved since the round before. */
    public function edits(Path $file): bool
    {
        return $this->edited->has($file);
    }

    /** Whether a test of a saved test file runs a line of a file. */
    public function retests(Path $file): bool
    {
        return $this->retested->has($file);
    }
}
