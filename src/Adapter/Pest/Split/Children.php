<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_column;
use function array_diff;
use function array_key_exists;
use function count;

use const FILE_APPEND;

use function file_put_contents;
use function function_exists;
use function hrtime;
use function is_int;

use const LOCK_EX;

use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function outbreak_steps;
use function outbreak_steps_bind;
use function outbreak_steps_limit;
use function outbreak_steps_share;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;

use const PHP_INT_MAX;

use function posix_kill;
use function range;

use const SIGKILL;
use const SIGUSR1;
use const SIGXCPU;

use function usleep;

use const WNOHANG;

/**
 * A split stream's running children (ADR-0117): no more of them at once than
 * the plan allows, each held to its limit, a child that ended without writing
 * how its test ended written for it, and each that failed or ended in its
 * test named, so the stream can kill its mutant. Where the stream counts
 * steps (ADR-0147), each child is bound to a slot of limits it shares with
 * its parent, unlimited until the parent ends the test, and a child past its
 * steps stops itself by `SIGXCPU`.
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

    /** Whether each child is held to its parent's steps. */
    private readonly bool $steps;

    /** @var list<array{string, string}> the children that failed or ended in their test while the parent forked */
    private array $pending = [];

    public function __construct(private readonly StreamPlan $plan)
    {
        $this->steps = $plan->limit->countsSteps()
            && function_exists('outbreak_steps_share')
            && outbreak_steps_share($plan->children);
    }

    /**
     * Forks a child for this mutant in this test, once a place is free: the
     * child's pid in the parent, 0 in the child, and -1 where PHP could not
     * fork.
     */
    public function forked(string $mutant, string $test): int
    {
        $failed = [];

        while (count($this->running) >= $this->plan->children) {
            $failed = [...$failed, ...$this->reaped(blocking: true)];
        }

        $this->pending = [...$this->pending, ...$failed];
        $slot = $this->freeSlot();
        $atFork = $this->stepsTaken();

        if ($this->steps) {
            outbreak_steps_limit($slot, PHP_INT_MAX);
        }

        $pid = pcntl_fork();

        if ($pid === 0) {
            $this->running = [];

            if ($this->steps) {
                outbreak_steps_bind($slot);
            }
        }

        if ($pid > 0) {
            $started = $this->now();
            $own = array_key_exists($test, $this->plan->times) ? $this->plan->times[$test] : false;
            $this->running[$pid] = [$mutant, $test, $started, $started + $this->secondsOf($own), $slot, $atFork];
        }

        return $pid;
    }

    /**
     * The parent ended this test in this many seconds: each child of it is
     * held to the limit that time sets, or, where the stream counts steps,
     * to the steps the parent took from the child's fork to here.
     */
    public function ended(string $test, float $seconds): void
    {
        $atEnd = $this->stepsTaken();

        foreach ($this->running as $pid => [$mutant, $of, $started, , $slot, $atFork]) {
            if ($of !== $test) {
                continue;
            }

            $this->running[$pid] = [$mutant, $of, $started, $started + $this->secondsOf($seconds), $slot, $atFork];

            if ($this->steps) {
                outbreak_steps_limit($slot, $this->plan->limit->stepsOf($atFork, $atEnd));
            }
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
        unset($this->running[$pid]);
        $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0;

        if ($this->steps && $signal === SIGXCPU) {
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
                unset($this->running[$pid]);
                $this->write(StreamLine::timedOut($mutant, $test));
            }
        }
    }

    /** The seconds a child may run in a test its parent took this long: the most, where the stream counts steps. */
    private function secondsOf(float|false $own): float
    {
        return $this->steps ? $this->plan->limit->longest() : $this->plan->limit->of($own);
    }

    /** A slot of limits no running child holds; the first where the stream counts no steps. */
    private function freeSlot(): int
    {
        $held = $this->steps ? array_column($this->running, self::SLOT) : [];
        $free = array_diff(range(0, $this->plan->children - 1), $held);

        return $free === [] ? 0 : min($free);
    }

    /** The steps the parent took so far; 0 where the stream counts none. */
    private function stepsTaken(): int
    {
        $taken = $this->steps ? outbreak_steps() : 0;

        return is_int($taken) ? $taken : 0;
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
