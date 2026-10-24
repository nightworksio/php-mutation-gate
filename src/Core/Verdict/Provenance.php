<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;

/**
 * Where a verdict comes from: the gate that judged it, the commit it judged,
 * the runner that ran its mutants, and the baseline file its floors are read
 * from (ADR-0009). What a run could not tell is not given.
 */
final readonly class Provenance
{
    private function __construct(
        private Version|NotGiven $gate,
        private Revision|NotGiven $commit,
        private Identity|NotGiven $runner,
        private Path|NotGiven $baseline,
    ) {
    }

    /** Nothing known of where a verdict comes from, as of a verdict no run judged. */
    public static function unknown(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value(), NotGiven::value());
    }

    /** The gate that judged the verdict, its floors read from this baseline file. */
    public static function of(Version $gate, Path $baseline): self
    {
        return new self($gate, NotGiven::value(), NotGiven::value(), $baseline);
    }

    /** This, judging this commit, by its full id. */
    public function judging(Revision $commit): self
    {
        return clone($this, ['commit' => $commit]);
    }

    /** This, its mutants run by this runner. */
    public function ranBy(Identity $runner): self
    {
        return clone($this, ['runner' => $runner]);
    }

    public function gate(): Version|NotGiven
    {
        return $this->gate;
    }

    public function commit(): Revision|NotGiven
    {
        return $this->commit;
    }

    public function runner(): Identity|NotGiven
    {
        return $this->runner;
    }

    public function baseline(): Path|NotGiven
    {
        return $this->baseline;
    }
}
