<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use const FILE_APPEND;

use function file_put_contents;
use function hrtime;
use function is_int;

use const LOCK_EX;

use function max;

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
 * A split stream's running children (ADR-0117): no more of them in places
 * at once than the plan allows now (see ChildPlaces), each held to its
 * limit, a child that ended without writing how its test ended written for
 * it, and each that failed or ended in its test named, so the stream can
 * kill its mutant. A child is stopped as past its limit only once its parent
 * ended the test, against the parent's own time of it on this machine, less
 * what the parent spent waiting for a place (ADR-0177); until then it is
 * held to `timeouts.most` alone, and one that ran past the coverage map's
 * time leaves its place where the parent needs it, and runs on beside the
 * places. Where the stream counts steps (ADR-0147), each child is bound to a
 * slot of limits it shares with its parent, unlimited until the parent ends
 * the test, and a child past its steps stops itself by `SIGXCPU`. The
 * databases copied for a child (ADR-0147) are dropped once it ended, and
 * each left as the process ends.
 *
 * @internal
 */
final class Children
{
    /** How long the parent sleeps between looks at children it waits for, in microseconds. */
    private const int WAIT = 500;

    private readonly HeldChildren $held;

    /** How long the parent waited for a place in the test it runs, in seconds. */
    private float $waited = 0.0;

    /** The steps each child is held to. */
    private readonly ChildSteps $steps;

    /** @var list<array{string, string}> the children that failed or ended in their test while the parent forked */
    private array $pending = [];

    /** @var array<int, Copies> the databases copied for each running child, by pid */
    private array $copies = [];

    public function __construct(private readonly StreamPlan $plan)
    {
        $this->steps = new ChildSteps($plan);
        $this->held = new HeldChildren();
        $plan->places->joined();
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
        $this->pending = [...$this->pending, ...$this->placeFreed()];
        $slot = $this->steps->freeSlot($this->held->slots());
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
     * held to the limit the parent's own time sets, less what it waited for
     * a place, or, where the stream counts steps, to the steps the parent
     * took from the child's fork to here.
     */
    public function ended(string $test, float $seconds): void
    {
        $atEnd = $this->steps->taken();
        $limit = $this->steps->secondsOf(max(0.0, $seconds - $this->waited));
        $this->waited = 0.0;

        foreach ($this->held->heldTo($test, $limit) as $child) {
            $this->steps->held($child->slot, $child->atFork, $atEnd);
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
            $before = $this->held->placed();
            $failed = [...$failed, ...$this->drained()];
            $this->stopped($this->held->lateBy($this->now()));
            $done = ! $blocking || $this->held->placed() < $before || ! $this->held->areAny();

            if (! $done) {
                usleep(self::WAIT);
            }
        } while (! $done);

        return $failed;
    }

    /**
     * Waits for every child, each held to its limit.
     *
     * @return list<array{string, string}> each that failed or ended in its test: its mutant, and the test
     */
    public function reapedAll(): array
    {
        $failed = [];

        while ($this->areRunning()) {
            $failed = [...$failed, ...$this->reaped(blocking: true)];
        }

        return $failed;
    }

    /** How long the parent waited for a place in its current test, in seconds. */
    public function waited(): float
    {
        return $this->waited;
    }

    /** Whether any child still runs. */
    public function areRunning(): bool
    {
        return $this->held->areAny();
    }

    /**
     * Waits until a place is free, taking every child that ended, stopping
     * each past its limit, and letting one out of its place, where none is
     * free, that its parent's test still runs and that ran past the map's
     * time.
     *
     * @return list<array{string, string}> each that failed or ended in its test: its mutant, and the test
     */
    private function placeFreed(): array
    {
        $from = $this->now();
        $failed = [];

        do {
            $failed = [...$failed, ...$this->drained()];
            $this->stopped($this->held->lateBy($this->now()));
            $room = $this->held->roomMade($this->plan->places->now(), $this->now());

            if (! $room) {
                usleep(self::WAIT);
            }
        } while (! $room);

        $this->waited += $this->now() - $from;

        return $failed;
    }

    /** In a child just forked, nothing of its parent's children kept, its steps counted against its slot. */
    private function inChild(int $slot): void
    {
        $this->held->forgotten();
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
        $own = array_key_exists($test, $this->plan->times) ? $this->plan->times[$test] : false;
        $forked = ForkedChild::forked(
            $mutant,
            $test,
            $slot,
            $atFork,
            $this->now(),
            $this->plan->limit->longest(),
            $this->steps->releasedAfter($own),
        );
        $this->held->started($pid, $forked);
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
        $child = $this->held->of($pid);

        if (! $child instanceof ForkedChild) {
            return [];
        }

        $this->gone($pid);
        $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : false;
        $signal = is_int($signal) ? $signal : 0;

        if ($this->steps->exceeded($signal)) {
            $this->write(StreamLine::timedOut($child->mutant, $child->test));

            return [];
        }

        if ($signal !== SIGKILL && $signal !== SIGUSR1) {
            $this->write(StreamLine::endedChild($child->mutant, $child->test, $this->exitCode($status)));
        }

        return $signal === SIGKILL ? [] : [[$child->mutant, $child->test]];
    }

    /** The code a child exited with, or -1 where a signal ended it. */
    private function exitCode(int $status): int
    {
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;

        return is_int($code) ? $code : -1;
    }

    /**
     * Stops each of these children past its limit: its mutant timed out in its test.
     *
     * @param array<int, ForkedChild> $late by pid
     */
    private function stopped(array $late): void
    {
        foreach ($late as $pid => $child) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $this->gone($pid);
            $this->write(StreamLine::timedOut($child->mutant, $child->test));
        }
    }

    /** A child that ended is held no more, and the databases copied for it are dropped. */
    private function gone(int $pid): void
    {
        $this->held->gone($pid);

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
