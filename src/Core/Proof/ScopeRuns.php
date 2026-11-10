<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

/**
 * What a scope's ledger holds of its runs, beside their proofs: the newest
 * commit whose verdict passed, the `last-passed` base, and the newest whose
 * run judged every unit it considered and was the last to write the ledger,
 * the `last-run` base (ADR-0005); and on the default branch, its newest run
 * that wrote the ledger, passed or failed, with the units it left open
 * (ADR-0141).
 */
final readonly class ScopeRuns
{
    private const string NEVER_PASSED = 'No commit of this scope has passed yet.';

    private const string NEVER_RUN = 'No run of this scope has judged every unit it considered yet.';

    private const string NEVER_JUDGED = 'No run of this scope has recorded what it judged yet.';

    private function __construct(
        private Passed|CannotTell $passed,
        private LastRun|CannotTell $lastRun,
        private Judged|CannotTell $judged,
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

    /** These, with the newest commit whose verdict passed, replacing the one held. */
    public function passing(Passed $passed): self
    {
        return new self($passed, $this->lastRun, $this->judged);
    }

    /** These, with the newest commit whose run judged every unit it considered, replacing the one held. */
    public function lastRunAt(LastRun $lastRun): self
    {
        return new self($this->passed, $lastRun, $this->judged);
    }

    /** These, with the newest run that wrote the ledger, passed or failed, replacing the one held (ADR-0141). */
    public function judging(Judged $judged): self
    {
        return new self($this->passed, $this->lastRun, $judged);
    }

    /**
     * These, after a run that wrote the ledger without judging every unit it
     * considered: no last run stands, so no later run reads its change since
     * one older than the proofs this one left.
     */
    public function cutShort(): self
    {
        return new self($this->passed, CannotTell::because(self::NEVER_RUN), $this->judged);
    }

    /**
     * Whether these tell of the same commit that passed, last run and run
     * judged as others do: each the same record, or none in both.
     */
    public function tellsAs(self $other): bool
    {
        return self::same($this->passed, $other->passed)
            && self::same($this->lastRun, $other->lastRun)
            && self::same($this->judged, $other->judged);
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

    private static function same(Passed|LastRun|Judged|CannotTell $one, Passed|LastRun|Judged|CannotTell $other): bool
    {
        return $one === $other || ($one instanceof CannotTell && $other instanceof CannotTell);
    }
}
