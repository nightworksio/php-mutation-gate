<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;

/**
 * Works out what the core defines, faster than the core's own PHP, for the
 * core to check and use (ADR-0029). The core writes every request and reads
 * every answer as untrusted input; an accelerator only computes. Where it
 * cannot answer, the gate computes the same in PHP, so no result ever
 * depends on one.
 */
interface Accelerator
{
    /** The answer to one request in the helper's protocol; or why there is none. */
    public function answer(Request $request): Answer|NotAccelerated;
}
