<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * `local.priority`: how `watch` and `serve` weigh their work against the
 * machine's other work (ADR-0120), which each platform's class of it says
 * (`local.macosClass`, `local.linuxNice`, `local.windowsClass`).
 */
enum Priority: string
{
    /** As any other process. */
    case Normal = 'normal';

    /** Below the work a person waits on: macOS QoS utility, nice 10 on Linux, below normal on Windows. */
    case Utility = 'utility';

    /** Work nobody waits on: macOS QoS background, nice 19 on Linux, idle on Windows. */
    case Background = 'background';

    /** The niceness of utility work on Linux. */
    private const int UTILITY_NICE = 10;

    /** The niceness of background work on Linux: the most there is. */
    private const int BACKGROUND_NICE = 19;

    /** The QoS clamp macOS runs the work under. */
    public function macos(): MacosClass
    {
        return match ($this) {
            self::Normal => MacosClass::None,
            self::Utility => MacosClass::Utility,
            self::Background => MacosClass::Background,
        };
    }

    /** The niceness Linux runs the work at. */
    public function nice(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Utility => self::UTILITY_NICE,
            self::Background => self::BACKGROUND_NICE,
        };
    }

    /** The priority class Windows runs the work in. */
    public function windows(): WindowsClass
    {
        return match ($this) {
            self::Normal => WindowsClass::Normal,
            self::Utility => WindowsClass::BelowNormal,
            self::Background => WindowsClass::Idle,
        };
    }
}
