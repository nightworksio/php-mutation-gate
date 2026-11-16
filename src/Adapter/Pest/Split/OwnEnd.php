<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use Closure;

use function posix_getpid;
use function posix_kill;

/**
 * How a process a stream forked ends itself: by a signal, which runs no
 * shutdown function and no destructor, so nothing the parent still holds is
 * written to or torn down from the child (ADR-0117). What a process asked to
 * run as it ends, such as writing what it measured, runs first.
 *
 * @internal
 */
final class OwnEnd
{
    /** @var list<Closure(): void> */
    private static array $first = [];

    /** Has this run, in this process and each it forks, before it ends itself. */
    public static function first(Closure $last): void
    {
        self::$first[] = $last;
    }

    /** Ends this process by this signal once each closure asked for has run. */
    public static function by(int $signal): void
    {
        foreach (self::$first as $last) {
            $last();
        }

        posix_kill(posix_getpid(), $signal);
    }
}
