<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function file_get_contents;
use function in_array;
use function is_readable;
use function is_string;
use function mb_strrpos;
use function mb_substr;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Whether a process a signal still reaches has ended, as Linux says. A
 * process that ended and whose parent has not yet collected it is still
 * there for a signal, so one waiting for it to be gone would wait for as
 * long as its parent leaves it; `/proc/<pid>/stat` names its state after
 * its command's closing bracket, `Z` once it ended and `X` as it goes.
 * Where that file cannot be read, as on macOS, nothing says it has ended.
 *
 * @internal
 */
final readonly class Living
{
    public const string STAT = '/proc/%d/stat';

    private const string COMMAND_ENDS = ')';

    private const array ENDED = ['Z', 'X'];

    /** Whether Linux says the process of this id has ended, its state read from this file where it is there. */
    public static function hasEnded(int $pid, string $stat = self::STAT): bool
    {
        return in_array(self::stateIn(sprintf($stat, $pid)), self::ENDED, strict: true);
    }

    /**
     * The state a process's stat file names; empty where it cannot be read
     * or names none, as where its process is gone between finding the file
     * and reading it, which the read says with a notice.
     */
    private static function stateIn(string $file): string
    {
        set_error_handler(static fn(): bool => true);

        try {
            $read = is_readable($file) ? file_get_contents($file) : false;
        } finally {
            restore_error_handler();
        }

        $ends = is_string($read) ? mb_strrpos($read, self::COMMAND_ENDS) : false;

        return is_string($read) && $ends !== false ? mb_substr($read, $ends + 2, 1) : '';
    }
}
