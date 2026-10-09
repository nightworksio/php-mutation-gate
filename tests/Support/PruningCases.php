<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\MutatorWindow;

/** Mutator windows as the pruning tests write them. */
final class PruningCases
{
    /** A mutator's window as a ledger wrote it: a `0` for each kill and a `1` for each mutant let through. */
    public static function window(string $mutator, string $outcomes, string|NotGiven $last = new NotGiven()): MutatorWindow
    {
        $window = MutatorWindow::written($mutator, $outcomes, $last);

        return $window instanceof MutatorWindow ? $window : MutatorWindow::of($mutator);
    }
}
