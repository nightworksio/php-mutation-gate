<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;

use function sprintf;

/**
 * The command a delivery is left by under `--deliver-later`, each in a directory of its own, so `deliver` sends
 * each stage's alone and in its own job (ADR-0007 decision 5): the plan's comment in its planned state, and the
 * verdict's ledger, comment, alerts and export.
 */
enum Stage: string
{
    case Planned = 'planned';
    case Verdict = 'verdict';

    /** Where the stage's delivery is: `.mutation-gate/delivery/<stage>`. */
    public function directory(): Path
    {
        return Path::of(sprintf('%s/%s', Workspace::delivery()->value(), $this->value));
    }
}
