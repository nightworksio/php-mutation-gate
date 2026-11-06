<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function array_keys;

use Closure;

use function is_int;

use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;

/**
 * The children a daemon of `mutation-gate serve` forked, one for each
 * request it runs (ADR-0078), and each child's connection to its client.
 *
 * @internal
 */
final class Children
{
    /** @var array<int, Connection> each running child's connection to its client, by the child's process id */
    private array $running = [];

    public function __construct(private readonly Forking $forking)
    {
    }

    /**
     * Whether the request runs in a child forked for it, which answers on
     * the connection and ends with the command's exit code.
     *
     * @param Closure(ServeRequest, Connection): int $run
     */
    public function forked(ServeRequest $request, Connection $connection, Closure $run): bool
    {
        $child = $this->forking->forked(static fn(): int => self::child($request, $connection, $run));

        if (is_int($child)) {
            $this->running[$child] = $connection;
        }

        return is_int($child);
    }

    /** @return array<int, Connection> each running child's connection, by its process id */
    public function connections(): array
    {
        return $this->running;
    }

    public function areNone(): bool
    {
        return $this->running === [];
    }

    /** Whether any child had ended; each that had is forgotten, its connection closed. */
    public function reaped(): bool
    {
        $any = false;

        foreach (array_keys($this->running) as $child) {
            if ($this->forking->hasEnded($child)) {
                unset($this->running[$child]);
                $any = true;
            }
        }

        return $any;
    }

    /** The child stopped, with every process under it, where its client is gone. */
    public function stoppedWhereGone(int $child): void
    {
        if ($this->running[$child]->hasEnded()) {
            $this->forking->stopped($child);
        }
    }

    /** Every child stopped, with every process under it. */
    public function allStopped(): void
    {
        foreach (array_keys($this->running) as $child) {
            $this->forking->stopped($child);
        }
    }

    /**
     * What the child runs: the request, its exit code sent: the code the
     * child ends with.
     *
     * @param Closure(ServeRequest, Connection): int $run
     */
    private static function child(ServeRequest $request, Connection $connection, Closure $run): int
    {
        $code = $run($request, $connection);
        $connection->sent(ServeFrame::exited($code)->line());
        $connection->closed();

        return $code;
    }
}
