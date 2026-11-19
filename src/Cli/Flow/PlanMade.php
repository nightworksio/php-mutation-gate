<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * A plan, the line that says how its coverage map was measured, where one
 * does, a line for each limit it learned, and a warning for each ledger it
 * was made without.
 *
 * @internal
 */
final readonly class PlanMade
{
    /** @param list<string> $learned */
    private function __construct(
        private Plan $plan,
        private string|NotGiven $coverage,
        private Warnings $unread,
        private array $learned,
    ) {
    }

    public static function of(Plan $plan, string|NotGiven $coverage): self
    {
        return new self($plan, $coverage, Warnings::none(), []);
    }

    /** This plan, made without the ledgers these warnings say the store could not read. */
    public function without(Warnings $unread): self
    {
        return new self($this->plan, $this->coverage, $unread, $this->learned);
    }

    /** This plan, with a line for each limit it learned, saying what it is and why (ADR-0073). */
    public function learning(string ...$lines): self
    {
        return new self($this->plan, $this->coverage, $this->unread, array_values($lines));
    }

    /**
     * This plan, made without a fresh map: briefed so, which its shards and
     * its verdict read, and saying how its map was read (ADR-0135).
     */
    public function plannedEarly(CoverageMeasured $early): self
    {
        return new self(
            $this->plan->briefed($this->plan->briefing()->plannedEarly()),
            $early->said(),
            $this->unread,
            $this->learned,
        );
    }

    /**
     * This plan, briefed with the digest of every input its run reads, which a passing verdict records (ADR-0198);
     * as it is where the inputs could not be read.
     */
    public function reading(Digest|CannotJudge $inputs): self
    {
        $briefing = $this->plan->briefing();

        return $inputs instanceof Digest
            ? new self(
                $this->plan->briefed($briefing->reading($briefing->inputs()->digested($inputs))),
                $this->coverage,
                $this->unread,
                $this->learned,
            )
            : $this;
    }

    /** @return list<string> a line for each limit the plan learned */
    public function learned(): array
    {
        return $this->learned;
    }

    public function plan(): Plan
    {
        return $this->plan;
    }

    /** How the plan's coverage map was measured: against a kept map, or why every test was measured. */
    public function coverage(): string|NotGiven
    {
        return $this->coverage;
    }

    /** A warning for each ledger the plan was made without, and why the store could not read it. */
    public function unread(): Warnings
    {
        return $this->unread;
    }
}
