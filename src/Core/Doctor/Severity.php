<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/** How much a finding matters to the next run (ADR-0017, decision 9). */
enum Severity: string
{
    /** A run would exit 2 or be cannot judge. */
    case WillFail = 'will-fail';

    /** A run would take longer than it needs to. */
    case Slow = 'slow';

    /** Nothing fails or slows, but something is worth changing. */
    case Advice = 'advice';

    /** The severity as a person reads it. */
    public function said(): string
    {
        return match ($this) {
            self::WillFail => 'will fail',
            self::Slow => 'slow',
            self::Advice => 'advice',
        };
    }
}
