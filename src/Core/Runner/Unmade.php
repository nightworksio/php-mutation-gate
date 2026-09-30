<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\Reason;

/** A run that made no mutant with the id asked for, as the runner says, such as after its code changed. */
final readonly class Unmade
{
    private function __construct(private Reason $why)
    {
    }

    public static function because(Reason $why): self
    {
        return new self($why);
    }

    public function why(): Reason
    {
        return $this->why;
    }
}
