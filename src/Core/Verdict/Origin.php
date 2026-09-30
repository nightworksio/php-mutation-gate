<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** Where a unit's result came from. */
enum Origin: string
{
    /** This run mutated it. */
    case Run = 'run';
    /** A proof whose key still matches. */
    case Proved = 'proved';
    /** In a change-scoped run, the newest result for a unit the change does not reach. */
    case Carried = 'carried';
}
