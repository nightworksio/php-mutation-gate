<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;

/**
 * A mutator whose change other mutators also make. It names them, and stands
 * down wherever one of them runs that names none (ADR-0021, decision 18).
 */
interface SameChange
{
    /** Each other mutator that makes its change. */
    public function madeAlsoBy(): NamedMutators;
}
