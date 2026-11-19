<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use Closure;

use function function_exists;
use function posix_getpid;
use function posix_kill;

/**
 * How a process the gate forks ends itself: by a signal, which runs no
 * shutdown function and no destructor, so nothing the parent still holds is
 * written to or torn down from the child (ADR-0117). What a process asked to
 * run as it ends, such as writing what it measured, runs first. A process
 * the signal does not end, as one that handles it, or one PHP cannot signal,
 * exits, so it never returns to code its parent runs.
 *
 * @internal
 */
final class OwnEnd
{
    /** The status a process exits with where its own signal did not end it. */
    public const int OUTLIVED = 70;

    /** @var list<Closure(): void> */
    private static array $first = [];

    /** Has this run, in this process and each it forks, before it ends itself. */
    public static function first(Closure $last): void
    {
        self::$first[] = $last;
    }

    /**
     * Ends this process by this signal once each closure asked for has run;
     * by exit, where the signal does not end it.
     */
    public static function by(int $signal): never
    {
        try {
            foreach (self::$first as $last) {
                $last();
            }
        } finally {
            if (function_exists('posix_kill')) {
                posix_kill(posix_getpid(), $signal);
            }

            exit(self::OUTLIVED);
        }
    }
}
