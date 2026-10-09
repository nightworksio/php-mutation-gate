<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/**
 * The plugin records nothing, guards nothing, names no killer, names no test,
 * orders no mutant's tests or stops no replay: the adapter did not start this Pest for that,
 * or this is, or is not, a mutant's own process.
 */
enum Off
{
    case Recording;
    case Guarding;
    case NamingKillers;
    case NamingTests;
    case Ordering;
    case Stopping;
}
