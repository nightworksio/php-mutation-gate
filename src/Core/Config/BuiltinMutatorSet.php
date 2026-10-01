<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The mutator sets this repository ships, by the name the registry holds each by (ADR-0023, decision 8). */
enum BuiltinMutatorSet: string
{
    case Default = 'default';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
