<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The runners this package builds in, by the name a config chooses each by. */
enum BuiltinRunner: string
{
    case Pest = 'pest';

    case Infection = 'infection';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
