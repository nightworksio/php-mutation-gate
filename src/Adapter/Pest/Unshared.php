<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** A mutation run that opens on its own suite, with no map another job handed over. */
enum Unshared
{
    case Coverage;
}
