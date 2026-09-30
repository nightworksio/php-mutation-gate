<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Config\TestOrder;

/**
 * The order a runner is asked to run each mutant's covering tests in
 * (ADR-0013, decision 3): its own, or the likely killers first by a kill
 * history and the rest fastest first. Ordering changes when a mutant's killer
 * is found, never whether it is.
 */
final readonly class Ordering
{
    private function __construct(private TestOrder $order, private KillHistory $history)
    {
    }

    /** The runner's own order. */
    public static function runner(): self
    {
        return new self(TestOrder::Runner, KillHistory::none());
    }

    /** The order `tests.order` names, the likely killers read from a history. */
    public static function of(TestOrder $order, KillHistory $history): self
    {
        return new self($order, $history);
    }

    /** Whether the likely killers run first, the rest fastest first. */
    public function putsKillersFirst(): bool
    {
        return $this->order === TestOrder::KillersFirst;
    }

    /** The history the likely killers are read from. */
    public function history(): KillHistory
    {
        return $this->history;
    }
}
