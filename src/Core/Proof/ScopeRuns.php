<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_any;
use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Digest;

/**
 * What a scope's ledger holds of its runs, beside their proofs: the newest
 * commit whose verdict passed, the `last-passed` base, and the newest whose
 * run judged every unit it considered and was the last to write the ledger,
 * the `last-run` base (ADR-0005); and on the default branch, its newest run
 * that wrote the ledger, passed or failed, with the units it left open
 * (ADR-0141); and the newest passes that record the digest of their inputs,
 * the newest first, one a digest, which a run whose every input is identical
 * reuses (ADR-0198).
 */
final readonly class ScopeRuns
{
    private const string NEVER_PASSED = 'No commit of this scope has passed yet.';

    private const string NEVER_RUN = 'No run of this scope has judged every unit it considered yet.';

    private const string NEVER_JUDGED = 'No run of this scope has recorded what it judged yet.';

    /** @param list<Passed> $reusable */
    private function __construct(
        private Passed|CannotTell $passed,
        private LastRun|CannotTell $lastRun,
        private Judged|CannotTell $judged,
        private array $reusable = [],
    ) {
    }

    public static function none(): self
    {
        return new self(
            CannotTell::because(self::NEVER_PASSED),
            CannotTell::because(self::NEVER_RUN),
            CannotTell::because(self::NEVER_JUDGED),
        );
    }

    /**
     * These, with the newest commit whose verdict passed, replacing the one held, and first among the reusable
     * passes where it records its inputs, in place of one that records the same.
     */
    public function passing(Passed $passed): self
    {
        return new self($passed, $this->lastRun, $this->judged, $this->reusableWith($passed));
    }

    /**
     * These, with an earlier pass that records its inputs after the reusable ones held, as a ledger lists them,
     * the newest first; one whose inputs a held one records already is left out.
     */
    public function reusing(Passed $earlier): self
    {
        $inputs = $earlier->judging()->inputs();
        $held = $inputs instanceof Digest
            && array_any($this->reusable, static fn(Passed $passed): bool => $passed->judging()->readSame($inputs));

        return $held || ! $inputs instanceof Digest
            ? $this
            : new self($this->passed, $this->lastRun, $this->judged, [...$this->reusable, $earlier]);
    }

    /** The newest passes that record the digest of their inputs, the newest first. */
    public function reusable(): Passes
    {
        return Passes::of(...$this->reusable);
    }

    /** These, with the newest commit whose run judged every unit it considered, replacing the one held. */
    public function lastRunAt(LastRun $lastRun): self
    {
        return new self($this->passed, $lastRun, $this->judged, $this->reusable);
    }

    /** These, with the newest run that wrote the ledger, passed or failed, replacing the one held (ADR-0141). */
    public function judging(Judged $judged): self
    {
        return new self($this->passed, $this->lastRun, $judged, $this->reusable);
    }

    /**
     * These, after a run that wrote the ledger without judging every unit it
     * considered: no last run stands, so no later run reads its change since
     * one older than the proofs this one left.
     */
    public function cutShort(): self
    {
        return new self($this->passed, CannotTell::because(self::NEVER_RUN), $this->judged, $this->reusable);
    }

    public function passed(): Passed|CannotTell
    {
        return $this->passed;
    }

    public function lastRun(): LastRun|CannotTell
    {
        return $this->lastRun;
    }

    /** The newest run that wrote the ledger, passed or failed, where one recorded it (ADR-0141). */
    public function judged(): Judged|CannotTell
    {
        return $this->judged;
    }

    /** @return list<Passed> the reusable passes, this one first where it records its inputs, and none twice */
    private function reusableWith(Passed $passed): array
    {
        $inputs = $passed->judging()->inputs();

        return $inputs instanceof Digest
            ? [$passed, ...array_values(array_filter(
                $this->reusable,
                static fn(Passed $held): bool => ! $held->judging()->readSame($inputs),
            ))]
            : $this->reusable;
    }
}
