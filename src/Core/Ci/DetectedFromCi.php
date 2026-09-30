<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** A choice the config leaves to the CI the run is in, such as the plan format where `ci.plan` is not set. */
final readonly class DetectedFromCi
{
    private function __construct()
    {
    }

    public static function choice(): self
    {
        return new self();
    }
}
