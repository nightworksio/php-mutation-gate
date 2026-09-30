<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The tree sources this package builds in, by the name a config chooses each by. */
enum BuiltinTreeSource: string
{
    case PhpUnit = 'phpunit';

    case Composer = 'composer';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
