<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `reach.lockMoves`: what a package's move in a `composer.lock` reaches (ADR-0030, ADR-0113). */
enum LockMoveReach: string
{
    /** Every unit. */
    case Everything = 'everything';

    /** Every unit where it moved a package `reach.lockPackages` names; otherwise none. */
    case Named = 'named';

    /** The units run by the tests that loaded a moved package. */
    case Loaded = 'loaded';
}
