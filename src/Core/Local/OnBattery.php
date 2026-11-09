<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * `local.onBattery`: what `watch` does while the machine runs on battery or
 * in Low Power Mode (ADR-0120). `pre-push` and `run` are never held.
 */
enum OnBattery: string
{
    /** One worker at background priority. */
    case Reduce = 'reduce';

    /** No round runs until the machine runs on power again; changes wait. */
    case Pause = 'pause';

    /** As on power. */
    case Run = 'run';
}
