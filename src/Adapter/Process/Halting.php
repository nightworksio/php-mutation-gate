<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use Closure;

use function function_exists;
use function is_callable;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_signal_get_handler;
use function posix_getpid;
use function posix_kill;

use const SIG_DFL;
use const SIGUSR2;

/**
 * The signal that halts the processes this machine runs for the gate
 * (ADR-0146): `watch` sends it to its own process once a save moves a unit
 * of the batch running, and reads nothing the batch came to. Each set of processes hears it
 * while it awaits their ends, as does every set it waits inside, and stops
 * every one still running. Where `pcntl` is missing, nothing hears it and
 * nothing sends it.
 *
 * @internal
 */
final class Halting
{
    private bool $heard = false;

    /** The handler the signal had before. */
    private Closure|int $before = SIG_DFL;

    private function __construct(private readonly bool $listening)
    {
    }

    /** The signal, heard from now until it is let go. */
    public static function listened(): self
    {
        if (! self::signals()) {
            return new self(listening: false);
        }

        $halting = new self(listening: true);
        $before = pcntl_signal_get_handler(self::signal());
        $halting->before = is_callable($before) ? Closure::fromCallable($before) : $before;
        pcntl_signal(self::signal(), $halting->hear(...));

        return $halting;
    }

    /** The signal sent to this process, where anything can hear it. */
    public static function halt(): void
    {
        if (self::signals()) {
            posix_kill(posix_getpid(), self::signal());
        }
    }

    /** Whether the signal was heard, taking any signal sent since the last look. */
    public function heard(): bool
    {
        if ($this->listening) {
            pcntl_signal_dispatch();
        }

        return $this->heard;
    }

    /** The signal handled again as it was before it was listened to. */
    public function letGo(): void
    {
        if ($this->listening) {
            pcntl_signal(self::signal(), $this->before);
        }
    }

    /** Whether this PHP can send and hear signals. */
    public static function signals(): bool
    {
        return function_exists('pcntl_signal') && function_exists('posix_kill');
    }

    /** The signal: SIGUSR2, which nothing else of the gate sends. */
    private static function signal(): int
    {
        return SIGUSR2;
    }

    /** @param array<array-key, int|string> $info what the signal came with */
    private function hear(int $signal, array $info): void
    {
        $this->heard = true;

        if ($this->before instanceof Closure) {
            ($this->before)($signal, $info);
        }
    }
}
