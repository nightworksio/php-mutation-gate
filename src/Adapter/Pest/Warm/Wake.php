<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function pcntl_signal;
use function posix_kill;

/**
 * What cuts the pause between a warm worker's or its client's looks short
 * (ADR-0023): a client that leaves a request wakes its worker, a worker
 * that writes how a run ended wakes the client that asked, and a worker
 * wakes as its child ends. The signal is one a process ignores where it set
 * no handler, so a wake that reaches a process not listening, or no longer,
 * does nothing; a pause that no wake cuts short still ends at its interval.
 *
 * @internal
 */
final readonly class Wake
{
    public const int SIGNAL = SIGURG;

    /** The signals that cut this process's pauses short, each one that ends none of them. */
    private const array HEARD = [self::SIGNAL, SIGCHLD];

    /** This process's pauses cut short by a wake and by a child's end, neither of which ends it. */
    public static function heard(): void
    {
        foreach (self::HEARD as $signal) {
            pcntl_signal($signal, static function (): void {
            });
        }
    }

    /** Neither cuts this process's pauses short any more, as in a child a worker forks, which runs as a fresh run. */
    public static function unheard(): void
    {
        foreach (self::HEARD as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    /** Wakes the process with this id. */
    public static function sent(int $pid): void
    {
        posix_kill($pid, self::SIGNAL);
    }
}
