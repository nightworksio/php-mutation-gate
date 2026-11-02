<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/**
 * Where the project does not apply `pest:patch`, so no shard's opening run is a canary group.
 *
 * @internal
 */
enum Unpatched
{
    case Vendor;
}
