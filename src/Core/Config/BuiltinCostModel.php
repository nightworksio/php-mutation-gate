<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The cost models this package builds in, by the name the registry holds each by. */
enum BuiltinCostModel: string
{
    case Learned = 'learned';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
