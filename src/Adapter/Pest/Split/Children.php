<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function count;

use const FILE_APPEND;

use function file_put_contents;
use function hrtime;
use function is_int;

use const LOCK_EX;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_kill;

use const SIGKILL;
use const SIGUSR1;

use function usleep;

use const WNOHANG;

/**
 * A split stream's running children (ADR-0117): no more of them at once than
 * the plan allows, each held to its limit, a child that ended without writing
 * how its test ended written for it, and each that failed or ended in its
 * test named, so the stream can kill its mutant.
 *
 * @internal
 */
final class Children
{
    /** How long the parent sleeps between looks at children it waits for, in microseconds. */
    private const int WAIT = 500;

    /** @var array<int, array{string, string, float, float}> each running child's mutant, test, start and end, by pid */
    private array $running = [];

    /** @var list<array{string, string}> the children that failed or ended in their test while the parent forked */
    private array $pending = [];

    public function __construct(private readonly StreamPlan $plan)
    {
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
        $pid = pcntl_fork();

        if ($pid === 0) {
            $this->running = [];
        }

        if ($pid > 0) {
            $started = self::now();
            $own = array_key_exists($test, $this->plan->times) ? $this->plan->times[$test] : false;
            $this->running[$pid] = [$mutant, $test, $started, $started + $this->plan->limit->of($own)];
        }

        return $pid;
    }

    /** The parent ended this test in this many seconds: each child of it is held to the limit that time sets. */
    public function ended(string $test, float $seconds): void
    {
        foreach ($this->running as $pid => [$mutant, $of, $started]) {
            if ($of === $test) {
                $this->running[$pid] = [$mutant, $of, $started, $started + $this->plan->limit->of($seconds)];
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
     * one that passed by SIGKILL, and one that did neither ended in its test,
     * by an exit, another signal or a crash, which it wrote nothing of.
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

        if ($signal !== SIGKILL && $signal !== SIGUSR1) {
            $this->write(StreamLine::endedChild($mutant, $test, self::exitCode($status)));
        }

        return $signal === SIGKILL ? [] : [[$mutant, $test]];
    }

    /** The code a child exited with, or -1 where a signal ended it. */
    private static function exitCode(int $status): int
    {
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;

        return is_int($code) ? $code : -1;
    }

    /** Stops each child past its limit. */
    private function stopLate(): void
    {
        $now = self::now();

        foreach ($this->running as $pid => [$mutant, $test, , $deadline]) {
            if ($now > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                unset($this->running[$pid]);
                $this->write(StreamLine::timedOut($mutant, $test));
            }
        }
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->results, $line, FILE_APPEND | LOCK_EX);
    }

    private static function now(): float
    {
        return hrtime(as_number: true) / Seconds::NANOSECONDS;
    }
}
