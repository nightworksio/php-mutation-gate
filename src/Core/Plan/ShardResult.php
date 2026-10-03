<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What one shard left for the verdict: the plan it followed, its units with
 * their keys, every mutant's record or the runner's cannot judge, what it
 * measured, the mutants that gave two answers, the held units whose holding
 * tests miss lines of them, which it did not mutate, what it warns of, the
 * units its budget ran out before, and what static analysis's checks of its
 * survivors came to.
 */
final readonly class ShardResult
{
    private function __construct(
        private Digest $plan,
        private ShardId $shard,
        private Keys $units,
        private MutationResult|CannotJudge $outcome,
        private Measurement $measured,
        private MutantIds $flaky,
        private HeldMisses $misses,
        private HeldCovered $covered,
        private Warnings $warnings,
        private Units $unjudged,
        private SurvivorChecks $checks,
    ) {
    }

    public static function of(
        Digest $plan,
        ShardId $shard,
        Keys $units,
        MutationResult|CannotJudge $outcome,
        Measurement $measured,
    ): self {
        return new self(
            $plan,
            $shard,
            $units,
            $outcome,
            $measured,
            MutantIds::none(),
            HeldMisses::none(),
            HeldCovered::none(),
            Warnings::none(),
            Units::none(),
            SurvivorChecks::none(),
        );
    }

    /** This result, with the survivors a second run killed, which are flaky (ADR-0008). */
    public function withFlaky(MutantIds $flaky): self
    {
        return clone($this, ['flaky' => $flaky]);
    }

    /** This result, with the held units whose holding tests miss lines of them, which it did not mutate. */
    public function withMisses(HeldMisses $misses): self
    {
        return clone($this, ['misses' => $misses]);
    }

    /** This result, with the held units whose holding tests cover them, each with the tests that run it. */
    public function withCovered(HeldCovered $covered): self
    {
        return clone($this, ['covered' => $covered]);
    }

    /** This result, with what the shard warns of, which judges nothing. */
    public function withWarnings(Warnings $warnings): self
    {
        return clone($this, ['warnings' => $warnings]);
    }

    /** This result, with the units the budget ran out before, which it never started (ADR-0008, decision 1). */
    public function withUnjudged(Units $unjudged): self
    {
        return clone($this, ['unjudged' => $unjudged]);
    }

    /** This result, with what static analysis's checks of its survivors came to (ADR-0020, decision 11). */
    public function withChecks(SurvivorChecks $checks): self
    {
        return clone($this, ['checks' => $checks]);
    }

    /** The digest of the plan the shard followed. */
    public function plan(): Digest
    {
        return $this->plan;
    }

    public function shard(): ShardId
    {
        return $this->shard;
    }

    /** The shard's units, each with its content key or why it has none. */
    public function units(): Keys
    {
        return $this->units;
    }

    /** Every mutant the runner reported, or why it could not judge, with its output. */
    public function outcome(): MutationResult|CannotJudge
    {
        return $this->outcome;
    }

    public function measured(): Measurement
    {
        return $this->measured;
    }

    /** The mutants that survived once and were killed when run again. */
    public function flaky(): MutantIds
    {
        return $this->flaky;
    }

    /** The held units whose holding tests miss lines of them. */
    public function misses(): HeldMisses
    {
        return $this->misses;
    }

    /** The held units whose holding tests cover them, each with the tests of theirs that run it. */
    public function covered(): HeldCovered
    {
        return $this->covered;
    }

    /** What the shard warns of. */
    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** The units the budget ran out before, which the shard never started. */
    public function unjudged(): Units
    {
        return $this->unjudged;
    }

    /** What static analysis's checks of the shard's survivors came to. */
    public function checks(): SurvivorChecks
    {
        return $this->checks;
    }
}
