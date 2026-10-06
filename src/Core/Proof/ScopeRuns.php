<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

/**
 * What a scope's ledger holds of its runs, beside their proofs: the newest
 * commit whose verdict passed, the `last-passed` base, and the newest whose
 * run judged every unit it considered and was the last to write the ledger,
 * the `last-run` base (ADR-0005, decision 2).
 */
final readonly class ScopeRuns
{
    private const string NEVER_PASSED = 'No commit of this scope has passed yet.';

    private const string NEVER_RUN = 'No run of this scope has judged every unit it considered yet.';

    private function __construct(private Passed|CannotTell $passed, private LastRun|CannotTell $lastRun)
    {
    }

    public static function none(): self
    {
        return new self(CannotTell::because(self::NEVER_PASSED), CannotTell::because(self::NEVER_RUN));
    }

    /** These, with the newest commit whose verdict passed, replacing the one held. */
    public function passing(Passed $passed): self
    {
        return clone($this, ['passed' => $passed]);
    }

    /** These, with the newest commit whose run judged every unit it considered, replacing the one held. */
    public function lastRunAt(LastRun $lastRun): self
    {
        return clone($this, ['lastRun' => $lastRun]);
    }

    /**
     * These, after a run that wrote the ledger without judging every unit it
     * considered: no last run stands, so no later run reads its change since
     * one older than the proofs this one left.
     */
    public function cutShort(): self
    {
        return clone($this, ['lastRun' => CannotTell::because(self::NEVER_RUN)]);
    }

    public function passed(): Passed|CannotTell
    {
        return $this->passed;
    }

    public function lastRun(): LastRun|CannotTell
    {
        return $this->lastRun;
    }
}
