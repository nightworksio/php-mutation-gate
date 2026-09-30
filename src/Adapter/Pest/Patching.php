<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Test\Group;

/**
 * Whether the project applies `pest:patch`, and the canary group a shard's
 * opening run is when it does.
 */
final readonly class Patching
{
    private function __construct(private Group|Unpatched $canary)
    {
    }

    public static function off(): self
    {
        return new self(Unpatched::Vendor);
    }

    public static function on(Group $canary): self
    {
        return new self($canary);
    }

    public function isOn(): bool
    {
        return $this->canary instanceof Group;
    }

    /** The canary group, where the project applies the patch. */
    public function canary(): Group|Unpatched
    {
        return $this->canary;
    }
}
