<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

/**
 * The keys of a manifest's `autoload` whose values are paths, in the order
 * the gate reads them. `exclude-from-classmap` names paths too, but to leave
 * them out, so it names no tree.
 */
enum AutoloadKind: string
{
    case Psr4 = 'psr-4';
    case Psr0 = 'psr-0';
    case Classmap = 'classmap';
    case Files = 'files';
}
