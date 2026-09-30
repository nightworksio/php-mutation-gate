<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What mutating one unit is expected to take, and what that rests on. */
final readonly class Estimated
{
    private function __construct(private Seconds $seconds, private CostBasis $basis)
    {
    }

    public static function of(Seconds $seconds, CostBasis $basis): self
    {
        return new self($seconds, $basis);
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    public function basis(): CostBasis
    {
        return $this->basis;
    }
}
