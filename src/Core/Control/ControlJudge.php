<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;

/** What judges a mutant by what its unmutated control found (see MutantControls). */
interface ControlJudge
{
    /** The mutant as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant;
}
