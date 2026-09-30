<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The presets this package ships (ADR-0008), by the name a config chooses each by. */
enum BuiltinPreset: string
{
    case Library = 'library';

    case Laravel = 'laravel';

    case Symfony = 'symfony';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
