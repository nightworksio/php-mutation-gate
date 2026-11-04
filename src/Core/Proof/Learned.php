<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Pruning\Survival;

/**
 * What a scope's runs learned beside its proofs: which tests killed its
 * mutants first, what each static analyser came to, what each mutator's
 * newest mutants came to, what each mutator's mutants cost and found, and
 * what the limits a config has the gate learn are read from.
 * Losing any of it costs a run speed or a report, never a verdict.
 */
final readonly class Learned
{
    private function __construct(
        private KillHistory $killers,
        private AnalyserHistories $analysers,
        private Survival $survival,
        private MutatorTallies $tallies,
        private LimitHistory $limits,
    ) {
    }

    public static function none(): self
    {
        return new self(
            KillHistory::none(),
            AnalyserHistories::none(),
            Survival::none(),
            MutatorTallies::none(),
            LimitHistory::none(),
        );
    }

    /** This, with what a run learned of one kind, replacing what it held of that kind. */
    public function with(KillHistory|AnalyserHistories|Survival|MutatorTallies|LimitHistory $learned): self
    {
        return new self(
            $learned instanceof KillHistory ? $learned : $this->killers,
            $learned instanceof AnalyserHistories ? $learned : $this->analysers,
            $learned instanceof Survival ? $learned : $this->survival,
            $learned instanceof MutatorTallies ? $learned : $this->tallies,
            $learned instanceof LimitHistory ? $learned : $this->limits,
        );
    }

    /** This and what another scope learned, read together: where both learned of the same thing, this one's. */
    public function and(self $other): self
    {
        return new self(
            $this->killers->and($other->killers),
            $this->analysers->and($other->analysers),
            $this->survival->and($other->survival),
            $this->tallies->and($other->tallies),
            $this->limits->and($other->limits),
        );
    }

    /** This with the kill history of the functions in these files only, which are the ones that still exist. */
    public function keepingKillersIn(Paths $files): self
    {
        return new self(
            $this->killers->onlyIn($files),
            $this->analysers,
            $this->survival,
            $this->tallies,
            $this->limits,
        );
    }

    /** Which tests killed the scope's mutants first, and its functions' mutants. */
    public function killers(): KillHistory
    {
        return $this->killers;
    }

    /** What the scope's runs learned of each static analyser: its rejection rates and how long its checks take. */
    public function analysers(): AnalyserHistories
    {
        return $this->analysers;
    }

    /** What each mutator's newest judged mutants in the scope came to, by runner, which decides pruning. */
    public function survival(): Survival
    {
        return $this->survival;
    }

    /** What each mutator's mutants in the scope cost and found, by runner (ADR-0076). */
    public function tallies(): MutatorTallies
    {
        return $this->tallies;
    }

    /** What the limits a config has the gate learn are read from (ADR-0073). */
    public function limits(): LimitHistory
    {
        return $this->limits;
    }
}
