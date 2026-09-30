<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/**
 * The plugin records nothing, or guards nothing: the adapter did not start
 * this Pest for that, or it is a mutant's own process.
 */
enum Off
{
    case Recording;
    case Guarding;
}
