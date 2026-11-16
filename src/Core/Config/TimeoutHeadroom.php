<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `timeouts.headroom`: the share of a timed-out mutant's limit its unmutated
 * tests must have left unused for the timeout to count as a kill (ADR-0108).
 * Tests that nearly reach the limit unmutated may reach it by chance.
 */
final readonly class TimeoutHeadroom
{
    /** A tenth of the limit, where `timeouts.headroom` is not written. */
    public const float STANDARD = 0.1;

    /** The most of a limit the setting may keep unused. */
    public const float MOST = 0.9;

    private function __construct(private int|float $share)
    {
    }

    public static function of(int|float $share): self
    {
        return new self($share);
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** Whether tests that took this time left this share of this limit unused. */
    public function isLeftBy(Seconds $time, Seconds $limit): bool
    {
        return $time->seconds() <= $limit->seconds() * (1 - $this->share);
    }
}
