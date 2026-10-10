<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Port\Accelerator;

/** No helper to ask: turned off, not installed, or refused when it was found, and why. */
final readonly class Unavailable implements Accelerator
{
    private function __construct(private NotAccelerated $why)
    {
    }

    public static function because(NotAccelerated $why): self
    {
        return new self($why);
    }

    public function answer(Request $request): NotAccelerated
    {
        return $this->why;
    }
}
