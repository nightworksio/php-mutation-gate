<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;

/**
 * A file of a cgroup, read and written as Linux's cgroup v2 takes it, where
 * the kernel refuses a write it does not take with a warning (ADR-0158).
 *
 * @internal
 */
final readonly class CgroupFile
{
    /** What a file of a cgroup holds; nothing where it cannot be read. */
    public static function read(string $cgroup, CgroupKnob $knob): string
    {
        return self::at($knob->of($cgroup));
    }

    /** What a file Linux says its processes' state in holds; nothing where it cannot be read. */
    public static function at(string $path): string
    {
        $read = is_file($path) ? file_get_contents($path) : false;

        return is_string($read) ? $read : '';
    }

    /** Whether this process made the cgroup, which no other process made before it. */
    public static function made(string $cgroup): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return mkdir($cgroup);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether a cgroup was removed: Linux refuses one a process still runs in,
     * and a directory that is not a cgroup and holds files is not removed;
     * either refusal is kept from the run's output.
     */
    public static function removed(string $cgroup): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return is_dir($cgroup) && rmdir($cgroup);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether a value was written to a file of a cgroup, the cgroup made
     * first where asked; a refusal is a write that failed, kept from the
     * run's output.
     */
    public static function wrote(string $cgroup, CgroupKnob $knob, string $value, bool $made = false): bool
    {
        $path = $knob->of($cgroup);
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return (! $made || is_dir($cgroup) || mkdir($cgroup))
                && is_file($path)
                && file_put_contents($path, $value) !== false;
        } finally {
            restore_error_handler();
        }
    }
}
