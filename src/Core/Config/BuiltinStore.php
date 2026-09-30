<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The proof stores this package builds in, by the name a config chooses each by. */
enum BuiltinStore: string
{
    case Directory = 'directory';

    case S3 = 's3';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
