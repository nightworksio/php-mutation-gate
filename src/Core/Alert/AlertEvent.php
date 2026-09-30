<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

/**
 * How the default branch changed state, which is what an alert says: it
 * failed, it cannot be judged, it passes again after either, or a floor went
 * down (ADR-0016, decision 10).
 */
enum AlertEvent: string
{
    case Failed = 'failed';
    case CannotJudge = 'cannot-judge';
    case Recovered = 'recovered';
    case FloorLowered = 'floor-lowered';

    /** The event in words, as a message's title says it. */
    public function said(): string
    {
        return match ($this) {
            self::Failed => 'failed',
            self::CannotJudge => 'cannot judge',
            self::Recovered => 'recovered',
            self::FloorLowered => 'floor lowered',
        };
    }
}
