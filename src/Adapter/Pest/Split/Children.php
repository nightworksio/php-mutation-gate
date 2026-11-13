<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_column;
use function array_key_exists;
use function count;

use const FILE_APPEND;

use function file_put_contents;
use function hrtime;
use function is_int;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_kill;
use function register_shutdown_function;

use const SIGKILL;
use const SIGUSR1;

use function usleep;

use const WNOHANG;

/**
 * A split stream's running children (ADR-0117): no more of them at once than
 * the plan allows, each held to its limit, a child that ended without writing
 * how its test ended written for it, and each that failed or ended in its
 * test named, so the stream can kill its mutant. Where the stream counts
 * steps (ADR-0147), each child is bound to a slot of limits it shares with
 * its parent, unlimited until the parent ends the test, and a child past its
 * steps stops itself by `SIGXCPU`. The databases copied for a child
 * (ADR-0147) are dropped once it ended, and each left as the process ends.
 *
 * @internal
 */
final class Children
{
    /** How long the parent sleeps between looks at children it waits for, in microseconds. */
    private const int WAIT = 500;

    /** Where a running child's slot of limits sits among what is kept of it. */
    private const int SLOT = 4;

    /**
     * @var array<int, array{string, string, float, float, int, int}> each running child's mutant, test, start, end,
     *                                                                 slot and steps at its fork, by pid
     */
    private array $running = [];

    /** The steps each child is held to. */
    private readonly ChildSteps $steps;

    /** @var list<array{string, string}> the children that failed or ended in their test while the parent forked */
    private array $pending = [];

    /** @var array<int, Copies> the databases copied for each running child, by pid */
    private array $copies = [];

    public function __construct(private readonly StreamPlan $plan)
    {
        $this->steps = new ChildSteps($plan);
        register_shutdown_function($this->leftDropped(...));
    }

    /**
     * Forks a child for this mutant in this test, once a place is free, on
     * the databases copied for it, which the parent drops once it ended: the
     * child's pid in the parent, 0 in the child, and -1 where PHP could not
     * fork.
     */
    public function forked(string $mutant, string $test, Copies $copies): int
    {
        $failed = [];

        while (count($this->running) >= $this->plan->children) {
            $failed = [...$failed, ...$this->reaped(blocking: true)];
        }

        $this->pending = [...$this->pending, ...$failed];
        $slot = $this->steps->freeSlot(array_column($this->running, self::SLOT));
        $atFork = $this->steps->taken();
        $this->steps->opened($slot);
        $pid = pcntl_fork();

        match (true) {
            $pid === 0 => $this->inChild($slot),
            $pid > 0 => $this->running($pid, [$mutant, $test, $slot, $atFork], $copies),
            default => $copies->dropped(),
        };

        return $pid;
    }

    /**
     * The parent ended this test in this many seconds: each child of it is
     * held to the limit that time sets, or, where the stream counts steps,
     * to the steps the parent took from the child's fork to here.
     */
    public function ended(string $test, float $seconds): void
    {
        $atEnd = $this->steps->taken();

        foreach ($this->running as $pid => [$mutant, $of, $started, , $slot, $atFork]) {
            if ($of !== $test) {
                continue;
            }

            $deadline = $started + $this->steps->secondsOf($seconds);
            $this->running[$pid] = [$mutant, $of, $started, $deadline, $slot, $atFork];
            $this->steps->held($slot, $atFork, $atEnd);
        }
    }

    /**
     * Takes every child that ended, and stops each past its limit; where
     * blocking, waits until at least one ended or was stopped.
     *
     * @return list<array{string, string}> each that failed or ended in its test: its mutant, and the test
     */
    public function reaped(bool $blocking): array
    {
        $failed = $this->pending;
        $this->pending = [];

        do {
            $before = count($this->running);
            $failed = [...$failed, ...$this->drained()];
            $this->stopLate();
            $done = ! $blocking || count($this->running) < $before || $this->running === [];

            if (! $done) {
                usleep(self::WAIT);
            }
        } while (! $done);

        return $failed;
    }

    /** Whether any child still runs. */
    public function areRunning(): bool
    {
        return $this->running !== [];
    }

    /** In a child just forked, nothing of its parent's children kept, its steps counted against its slot. */
    private function inChild(int $slot): void
    {
        $this->running = [];
        $this->copies = [];
        $this->steps->bound($slot);
    }

    /**
     * A child just forked kept as running, on the databases copied for it.
     *
     * @param array{string, string, int, int} $child its mutant, test, slot and the parent's steps at its fork
     */
    private function running(int $pid, array $child, Copies $copies): void
    {
        [$mutant, $test, $slot, $atFork] = $child;
        $started = $this->now();
        $own = array_key_exists($test, $this->plan->times) ? $this->plan->times[$test] : false;
        $this->running[$pid] = [$mutant, $test, $started, $started + $this->steps->secondsOf($own), $slot, $atFork];
        $this->copies[$pid] = $copies;
    }

    /**
     * Takes every child that has ended, without waiting.
     *
     * @return list<array{string, string}> each that failed or ended in its test: its mutant, and the test
     */
    private function drained(): array
    {
        $failed = [];

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $failed = [...$failed, ...$this->endedOf($pid, is_int($status) ? $status : 0)];
        }

        return $failed;
    }

    /**
     * How a child ended: one that failed its test stopped itself by SIGUSR1,
     * one that passed by SIGKILL, one past its steps by SIGXCPU, a timeout,
     * and one that did none of these ended in its test, by an exit, another
     * signal or a crash, which it wrote nothing of.
     *
     * @return list<array{string, string}>
     */
    private function endedOf(int $pid, int $status): array
    {
        if (! array_key_exists($pid, $this->running)) {
            return [];
        }

        [$mutant, $test] = $this->running[$pid];
        $this->gone($pid);
        $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : false;
        $signal = is_int($signal) ? $signal : 0;

        if ($this->steps->exceeded($signal)) {
            $this->write(StreamLine::timedOut($mutant, $test));

            return [];
        }

        if ($signal !== SIGKILL && $signal !== SIGUSR1) {
            $this->write(StreamLine::endedChild($mutant, $test, $this->exitCode($status)));
        }

        return $signal === SIGKILL ? [] : [[$mutant, $test]];
    }

    /** The code a child exited with, or -1 where a signal ended it. */
    private function exitCode(int $status): int
    {
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;

        return is_int($code) ? $code : -1;
    }

    /** Stops each child past its limit. */
    private function stopLate(): void
    {
        $now = $this->now();

        foreach ($this->running as $pid => [$mutant, $test, , $deadline]) {
            if ($now > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->gone($pid);
                $this->write(StreamLine::timedOut($mutant, $test));
            }
        }
    }

    /** A child that ended no longer runs, and the databases copied for it are dropped. */
    private function gone(int $pid): void
    {
        unset($this->running[$pid]);

        if (array_key_exists($pid, $this->copies)) {
            $this->copies[$pid]->dropped();
            unset($this->copies[$pid]);
        }
    }

    /** Each database copied for a child still running as the process ends dropped. */
    private function leftDropped(): void
    {
        foreach ($this->copies as $copies) {
            $copies->dropped();
        }

        $this->copies = [];
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->results, $line, FILE_APPEND | LOCK_EX);
    }

    private function now(): float
    {
        return hrtime(as_number: true) / Seconds::NANOSECONDS;
    }
}
