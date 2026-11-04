<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;

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
