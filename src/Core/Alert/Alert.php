<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * One change of the default branch's state, with the verdict that made it
 * and the newest trend entry it is told against (ADR-0016, decisions 10
 * and 12).
 */
final readonly class Alert
{
    private function __construct(private AlertEvent $event, private Verdict $verdict, private TrendEntry $previous)
    {
    }

    public static function of(AlertEvent $event, Verdict $verdict, TrendEntry $previous): self
    {
        return new self($event, $verdict, $previous);
    }

    public function event(): AlertEvent
    {
        return $this->event;
    }

    public function verdict(): Verdict
    {
        return $this->verdict;
    }

    /** The newest trend entry before this verdict, which holds each tree's previous floor and score. */
    public function previous(): TrendEntry
    {
        return $this->previous;
    }

    /** The trees whose floor went down since that entry. */
    public function lowered(): LoweredFloors
    {
        return LoweredFloors::between($this->verdict, $this->previous);
    }
}
