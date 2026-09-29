<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Test\Group;

/** The Pest runner's own settings (ADR-0004): `pest.patch` and `pest.canary`. */
final readonly class Pest
{
    public function __construct(private bool $patch, private Group $canary)
    {
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return $this->patch;
    }

    /** The group of tests that shows the patches work. */
    public function canary(): Group
    {
        return $this->canary;
    }
}
