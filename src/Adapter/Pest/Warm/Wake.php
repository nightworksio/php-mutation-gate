<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_values;
use function fclose;
use function fread;
use function fwrite;
use function getenv;
use function intdiv;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_async_signals;
use function pcntl_signal;
use function posix_kill;
use function restore_error_handler;
use function set_error_handler;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;

use function stream_select;
use function stream_set_blocking;

use const STREAM_SOCK_STREAM;

use function stream_socket_pair;
use function usleep;

/**
 * What cuts the pause between a warm worker's or its client's looks short
 * (ADR-0023, ADR-0120): a client that leaves a request wakes its worker, a
 * worker that writes how a run ended wakes the client that asked, and a
 * worker wakes as its child ends. Each wake writes a byte to a pipe of the
 * process's own that its pause blocks on, so a wake that comes just before
 * a pause ends it at once, never lost. The signal is one a process ignores
 * where it set no handler, so a wake that reaches a process not listening,
 * or no longer, does nothing; a pause that no wake cuts short still ends at
 * its interval, which is how a process notices one that went away.
 *
 * @internal
 */
final class Wake
{
    public const int SIGNAL = SIGURG;

    /** The signals that cut this process's pauses short, each one that ends none of them. */
    private const array HEARD = [self::SIGNAL, SIGCHLD];

    /** The most bytes a pause drains from its pipe at once. */
    private const int DRAINED = 64;

    /** The byte a wake writes. */
    private const string RUNG = '.';

    /** @var list<resource> this process's pipe of wakes: the end its pauses read, then the end its wakes write */
    private static array $pipe = [];

    /** Whether signals reached this process's handlers as they came before it heard wakes. */
    private static bool $async = false;

    /** This process's pauses cut short by a wake and by a child's end, neither of which ends it. */
    public static function heard(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::$pipe = is_array($pair) ? array_values($pair) : [];

        foreach (self::$pipe as $end) {
            stream_set_blocking($end, enable: false);
        }

        self::$async = pcntl_async_signals();
        pcntl_async_signals(enable: true);

        foreach (self::HEARD as $signal) {
            pcntl_signal($signal, static function (): void {
                self::rung();
            });
        }
    }

    /**
     * Neither cuts this process's pauses short any more, its pipe is closed
     * and signals reach handlers as they did before, as in a child a worker
     * forks, which runs as a fresh run.
     */
    public static function unheard(): void
    {
        foreach (self::HEARD as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        foreach (self::$pipe as $end) {
            fclose($end);
        }

        self::$pipe = [];
        pcntl_async_signals(self::$async);
    }

    /** How long a pause lasts where no wake cuts it short: `local.childWait` in the local loop, or `runner.poll`. */
    public static function interval(): Seconds
    {
        return Polling::warm(getenv(ChildVariable::WarmWait->value), getenv(ChildVariable::Poll->value));
    }

    /** Wakes the process with this id. */
    public static function sent(int $pid): void
    {
        posix_kill($pid, self::SIGNAL);
    }

    /**
     * This process paused this many microseconds at most, or until a wake
     * came since the last pause; as `usleep` pauses where it hears none.
     */
    public static function paused(int $microseconds): void
    {
        if (self::$pipe === []) {
            usleep($microseconds);

            return;
        }

        $read = [self::$pipe[0]];
        $write = null;
        $except = null;
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            stream_select(
                $read,
                $write,
                $except,
                intdiv($microseconds, Seconds::MICROSECONDS),
                $microseconds % Seconds::MICROSECONDS,
            );
        } finally {
            restore_error_handler();
        }

        do {
            $drained = fread(self::$pipe[0], self::DRAINED);
        } while (is_string($drained) && $drained !== '');
    }

    /** A wake left in this process's pipe, where it has one. */
    private static function rung(): void
    {
        if (self::$pipe !== []) {
            fwrite(self::$pipe[1], self::RUNG);
        }
    }
}
