<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/**
 * The plugin records nothing, guards nothing, or names no killer: the adapter
 * did not start this Pest for that, or this is, or is not, a mutant's own
 * process.
 */
enum Off
{
    case Recording;
    case Guarding;
    case NamingKillers;
}
