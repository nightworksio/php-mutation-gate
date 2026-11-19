<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * `local.windowsClass`: the priority class Windows runs `watch`'s work in
 * (ADR-0120), which the processes it starts inherit.
 */
enum WindowsClass: string
{
    case Normal = 'normal';

    case BelowNormal = 'below-normal';

    case Idle = 'idle';

    /** The least value PHP's `proc_nice` reads as below normal on Windows. */
    private const int BELOW_NORMAL = 6;

    /** The least value PHP's `proc_nice` reads as idle on Windows. */
    private const int IDLE = 10;

    /** The value PHP's `proc_nice` takes for the class on Windows. */
    public function nice(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::BelowNormal => self::BELOW_NORMAL,
            self::Idle => self::IDLE,
        };
    }
}
