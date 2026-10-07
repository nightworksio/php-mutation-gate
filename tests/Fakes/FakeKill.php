<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;

/**
 * The evidence RunnerFake gives a kill: its first failing test ran first,
 * as in a run that stops at its first failure. The fake knows no order its
 * tests ran in, so it gives no key, and sees no process end.
 */
final readonly class FakeKill
{
    public static function evidenceOf(Mutant $mutant): Evidence
    {
        return $mutant->status() === MutantStatus::Killed
            ? Evidence::none()->withPrefix(Prefix::at(1))
            : Evidence::none();
    }
}
