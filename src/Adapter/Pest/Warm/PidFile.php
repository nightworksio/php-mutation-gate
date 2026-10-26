<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function posix_kill;

/**
 * A file a Pest run's warm worker writes a process id to, its own or a
 * child's (ADR-0023), written whole (see WholeFile).
 */
final readonly class PidFile
{
    /** The process id the file holds; none where there is no file, or it holds no whole number. */
    public static function read(string $file): int|NotGiven
    {
        $at = WholeFile::read($file);

        return $at instanceof Node && $at->kind() === Kind::Integer ? $at->integer() : NotGiven::value();
    }

    /** Whether the process the file names is still there: signal 0 tells, and sends nothing. */
    public static function isRunning(string $file): bool
    {
        $pid = self::read($file);

        return is_int($pid) && posix_kill($pid, 0);
    }
}
