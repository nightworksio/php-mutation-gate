<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `ci.trustMergedPullRequests`: what the default branch takes from a merged pull request's pass (ADR-0139). */
enum MergedTrust: string
{
    /** Nothing: every merged range is mutated. */
    case Off = 'false';

    /** The pass of a pull request whose head is the landed tree. */
    case Tree = 'true';

    /** That, and where the landed tree differs, the change from the judged head to it alone is reached. */
    case Delta = 'delta';

    /** The value as the config writes it. */
    public function json(): bool|string
    {
        return match ($this) {
            self::Off => false,
            self::Tree => true,
            self::Delta => $this->value,
        };
    }

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Off => 'Ci::notTrustingMergedPullRequests()',
            self::Tree => 'Ci::trustingMergedPullRequests()',
            self::Delta => 'Ci::trustingMergedDeltas()',
        };
    }

    /** Whether it takes anything from a merged pull request. */
    public function trusts(): bool
    {
        return $this !== self::Off;
    }

    /** Whether it loosens what the default branch judges (ADR-0142). */
    public function isLoose(): bool
    {
        return $this !== self::Off;
    }
}
