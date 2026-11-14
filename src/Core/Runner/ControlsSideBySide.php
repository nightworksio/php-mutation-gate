<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Control\Controls;

/**
 * A runner that starts unmutated controls while the gate goes on, each as
 * its `controls()` runs it, in places and directories of their own, so the
 * survivors' runs again run beside them in the rest of the pool.
 *
 * @internal
 */
interface ControlsSideBySide
{
    /** What each of these controls finds, as `controls()` finds it, started now and taken once the gate needs it. */
    public function controlsStarted(MutationRequest $request, Controls $controls): PendingControls;
}
