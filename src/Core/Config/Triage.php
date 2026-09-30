<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How timeouts and flaky tests are judged (ADR-0008): `timeouts.mode`,
 * `timeouts.seconds`, `timeouts.retries` and `flaky.confirmSurvivors`.
 */
final readonly class Triage
{
    public function __construct(
        private TimeoutMode $timeouts,
        private Seconds $limit,
        private int $retries,
        private bool $confirmSurvivors,
        private TestOrder $order,
        private bool $staticEquivalence,
    ) {
    }

    /** `timeouts.mode` */
    public function timeouts(): TimeoutMode
    {
        return $this->timeouts;
    }

    /** `timeouts.seconds`: the cap on one mutant's run, and the covering tests' time past which it is skipped. */
    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** `timeouts.retries`: the most timed-out mutants retried per shard. */
    public function retries(): int
    {
        return $this->retries;
    }

    /** `flaky.confirmSurvivors`: whether each survivor is run once more before it counts. */
    public function confirmSurvivors(): bool
    {
        return $this->confirmSurvivors;
    }

    /** `tests.order`: the order each mutant's covering tests run in (ADR-0013). */
    public function order(): TestOrder
    {
        return $this->order;
    }

    /** `equivalence.static`: whether a mutant the optimizer compiles as its original is proven equivalent. */
    public function staticEquivalence(): bool
    {
        return $this->staticEquivalence;
    }
}
