<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * `local.macosClass`: the QoS clamp macOS runs `watch`'s and `serve`'s work
 * under, as `taskpolicy -c` takes it (ADR-0120); background work runs on the
 * efficiency cores of Apple silicon.
 */
enum MacosClass: string
{
    /** No clamp: the work runs as any other process. */
    case None = 'none';

    case Utility = 'utility';

    case Background = 'background';

    case Maintenance = 'maintenance';

    /** Whether the work runs under a clamp. */
    public function clamps(): bool
    {
        return $this !== self::None;
    }
}
