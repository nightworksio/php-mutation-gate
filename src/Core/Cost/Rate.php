<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a runner minute costs a team, as `costs.perRunnerMinute` gives it,
 * which prices every figure of a run's cost (ADR-0016, decision 7).
 */
final readonly class Rate
{
    private const float SECONDS_PER_MINUTE = 60.0;

    private function __construct(private Money $perMinute)
    {
    }

    public static function perMinute(float $amount, string $currency): self
    {
        return new self(Money::of($amount, $currency));
    }

    /** What one runner minute costs. */
    public function perRunnerMinute(): Money
    {
        return $this->perMinute;
    }

    /** What this much runner time costs. */
    public function of(Seconds $runner): Money
    {
        return Money::of(
            $this->perMinute->amount() * $runner->seconds() / self::SECONDS_PER_MINUTE,
            $this->perMinute->currency(),
        );
    }
}
