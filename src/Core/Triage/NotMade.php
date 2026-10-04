<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Triage;

/** A run of a unit that did not make one of the mutants another run made. */
final readonly class NotMade
{
    private function __construct()
    {
    }

    public static function inThatRun(): self
    {
        return new self();
    }
}
