<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/** What an ignore under `global-ignore` or a `@profile` applies to: every mutator, rather than one. */
final readonly class AnyMutator
{
    private function __construct()
    {
    }

    public static function of(): self
    {
        return new self();
    }
}
