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
    private function __construct(private bool $on, private Group $canary)
    {
    }

    public static function off(): self
    {
        return new self(on: false, canary: Group::named(''));
    }

    public static function on(Group $canary): self
    {
        return new self(on: true, canary: $canary);
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    public function canary(): Group
    {
        return $this->canary;
    }
}
