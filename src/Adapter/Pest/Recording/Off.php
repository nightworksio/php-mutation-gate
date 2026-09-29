<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/** The plugin records nothing: the adapter did not start this Pest, or it is a mutant's own process. */
enum Off
{
    case Recording;
}
