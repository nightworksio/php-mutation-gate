<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/** No check before the tests: every mutant goes to its tests, as under Infection or with no analyser. */
final readonly class NoPreCheck implements PreChecker
{
    public function rejected(PreCheckables $mutants, ProcessCount $side): Rejections
    {
        return Rejections::none();
    }
}
