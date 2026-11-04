<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

/**
 * What one mutator's mutants came to under one runner over every run that
 * wrote a ledger (ADR-0076): how many it made, how many were killed, how
 * many survived, a survivor or an uncovered mutant, how many an ignore left
 * out, and the runner time they took.
 */
final readonly class MutatorTally
{
    private function __construct(
        private string $runner,
        private string $mutator,
        private int $mutants,
        private int $killed,
        private int $survived,
        private int $ignored,
        private Seconds $seconds,
    ) {
    }

    /** A mutator of a runner, by the runner's names for both, of which nothing was tallied yet. */
    public static function of(string $runner, string $mutator): self
    {
        return new self($runner, $mutator, 0, 0, 0, 0, Seconds::none());
    }

    /** A tally as a ledger wrote it; none where its counts cannot be one. */
    public static function written(
        string $runner,
        string $mutator,
        int $mutants,
        int $killed,
        int $survived,
        int $ignored,
        float $seconds,
    ): self|NotGiven {
        $counted = $killed >= 0 && $survived >= 0 && $ignored >= 0 && $seconds >= 0.0;

        return $counted && $killed + $survived + $ignored <= $mutants
            ? new self($runner, $mutator, $mutants, $killed, $survived, $ignored, Seconds::of($seconds))
            : NotGiven::value();
    }

    /** This tally, with one more mutant, judged so, that took this long. */
    public function after(MutantJudgement $judgement, Seconds $took): self
    {
        return new self(
            $this->runner,
            $this->mutator,
            $this->mutants + 1,
            $this->killed + ($judgement->scoring(Uncovered::Count) === Scoring::Killed ? 1 : 0),
            $this->survived + ($judgement->asksForATest() ? 1 : 0),
            $this->ignored + ($judgement->isIgnored() ? 1 : 0),
            Seconds::of($this->seconds->seconds() + $took->seconds()),
        );
    }

    public function runner(): string
    {
        return $this->runner;
    }

    /** The mutator, by the runner's name for it. */
    public function mutator(): string
    {
        return $this->mutator;
    }

    public function mutants(): int
    {
        return $this->mutants;
    }

    public function killed(): int
    {
        return $this->killed;
    }

    public function survived(): int
    {
        return $this->survived;
    }

    /** How many an entry in the ignore list, the runner's own marker or proof it is equivalent left out. */
    public function ignored(): int
    {
        return $this->ignored;
    }

    /** The runner time its mutants took. */
    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** How many were judged to an answer: killed, or survived. */
    public function judged(): int
    {
        return $this->killed + $this->survived;
    }

    /** The share of its judged mutants that were killed; none where none was judged. */
    public function killRate(): Percentage|NotGiven
    {
        return $this->shareOf($this->killed, $this->judged());
    }

    /** The share of its mutants an ignore left out; none where it made none. */
    public function ignoredShare(): Percentage|NotGiven
    {
        return $this->shareOf($this->ignored, $this->mutants);
    }

    /** The runner time each kill cost; none where nothing was killed. */
    public function secondsPerKill(): Seconds|NotGiven
    {
        return $this->killed > 0 ? Seconds::of($this->seconds->seconds() / $this->killed) : NotGiven::value();
    }

    private function shareOf(int $part, int $whole): Percentage|NotGiven
    {
        $share = $whole > 0 ? Percentage::share($part, $whole) : NotGiven::value();

        return $share instanceof Percentage ? $share : NotGiven::value();
    }
}
