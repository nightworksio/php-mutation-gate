<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** What the runners' own ignore markers do (ADR-0008). */
enum NativeMarkers: string
{
    /** They stop the run before anything is mutated. */
    case Refuse = 'refuse';

    /** They are allowed while a project moves its ignores into the config. */
    case Allow = 'allow';
}
