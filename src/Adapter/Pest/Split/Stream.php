<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use Closure;

use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function hrtime;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;

use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_getpid;
use function posix_kill;
use function putenv;
use function sprintf;
use function usleep;

/**
 * A split stream's parent (ADR-0117): the unmutated suite runs once, and
 * where a test first reaches the site of a mutant still undecided, the
 * process forks. The child turns that mutant on, finishes only that test and
 * writes how it ended; the parent turns it off, goes on, and writes how each
 * of its own tests ended, which judges every child of that test. It holds no
 * more children at once than it is told, and stops a child that runs past
 * its limit: a multiple of the parent's own time of the same test, or of the
 * coverage map's while the parent is still in that test.
 *
 * It loads no class of the gate but SchemaSwitch and StreamLine, as every
 * other may be served a schema whose sites it decides.
 *
 * @internal
 */
final class Stream
{
    /** How long the parent sleeps between looks at children it waits for, in microseconds. */
    private const int WAIT = 500;

    /** Nanoseconds in a second. */
    private const float NANOSECONDS = 1e9;

    /** @var array<string, array<int, array{string, string, array<string, string>}>> each site's mutant, killer and variables, by file key and switch id */
    private static array $sites = [];

    /** @var array<string, float> each test's own time as the coverage map measured it, by its id */
    private static array $times = [];

    private static string $results = '';

    private static int $most = 1;

    private static float $multiple = 3.0;

    private static float $floor = 3.0;

    private static float $longest = 30.0;

    private static string $test = '';

    private static int $started = 0;

    private static bool $failed = false;

    private static bool $forkable = true;

    private static bool $deciding = false;

    /** @var array<string, true> the sites forked in the current test, by file key and switch id */
    private static array $forked = [];

    /** @var array<string, true> the mutants a child killed in a test the parent passed, by id */
    private static array $killed = [];

    /** @var array<string, bool> whether the parent passed each test it ended, by the test's id */
    private static array $passed = [];

    /** @var array<string, list<string>> the mutants a child failed, by the test the parent is still in */
    private static array $pending = [];

    /** @var array<int, array{string, string, int, float|null}> each running child's mutant, test, start and deadline, by pid */
    private static array $children = [];

    private static string $mutant = '';

    private static int $sockets = 0;

    /**
     * Starts a split stream from the plan this file holds; nothing where it
     * cannot be read, or where PHP cannot fork.
     */
    public static function from(string $plan): bool
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! is_file($plan)) {
            return false;
        }

        $read = json_decode((string) file_get_contents($plan), associative: true);

        if (! is_array($read) || ! is_string($read['results'] ?? null) || ! is_array($read['sites'] ?? null)) {
            return false;
        }

        self::$results = $read['results'];
        self::$sites = self::sitesOf($read['sites']);
        self::$times = self::timesOf(is_array($read['times'] ?? null) ? $read['times'] : []);
        self::$most = is_int($read['children'] ?? null) ? max(1, $read['children']) : 1;
        self::$multiple = self::number($read['multiple'] ?? null, self::$multiple);
        self::$floor = self::number($read['floor'] ?? null, self::$floor);
        self::$longest = self::number($read['longest'] ?? null, self::$longest);
        self::$sockets = Sockets::open();
        SchemaSwitch::splitting(self::reached(...), self::changed(...));

        return true;
    }

    /** A test begins: the sites it reaches are its own, and none of them is forked yet. */
    public static function began(string $test): void
    {
        self::$test = $test;
        self::$started = hrtime(as_number: true);
        self::$failed = false;
        self::$forked = [];
        self::$forkable = Sockets::open() <= self::$sockets;

        if (! self::$forkable && self::$mutant === '') {
            self::write(StreamLine::fallback($test));
        }
    }

    /** The test failed or errored, in this process. */
    public static function failed(): void
    {
        self::$failed = true;
    }

    /** The test runs in a process of its own, where no fork reaches it. */
    public static function isolated(): void
    {
        if (self::$forkable && self::$mutant === '') {
            self::write(StreamLine::fallback(self::$test));
        }

        self::$forkable = false;
    }

    /**
     * The test ended. A child writes how it ended and stops at once; the
     * parent writes how its own ended and holds each child of it to its
     * limit.
     */
    public static function ended(): void
    {
        if (self::$test === '') {
            return;
        }

        $seconds = (hrtime(as_number: true) - self::$started) / self::NANOSECONDS;

        if (self::$mutant !== '') {
            self::write(StreamLine::child(self::$mutant, self::$test, self::$failed, $seconds));
            pcntl_signal(SIGUSR1, SIG_DFL);
            posix_kill(posix_getpid(), self::$failed ? SIGUSR1 : SIGKILL);
        }

        self::write(StreamLine::parent(self::$test, ! self::$failed, $seconds));
        self::$passed[self::$test] = ! self::$failed;

        foreach (self::$pending[self::$test] ?? [] as $mutant) {
            self::kill($mutant, self::$test);
        }

        unset(self::$pending[self::$test]);
        $limit = max(self::$floor, $seconds * self::$multiple);

        foreach (self::$children as $pid => [$mutant, $test, $started, $deadline]) {
            if ($test === self::$test && $deadline === null) {
                self::$children[$pid] = [$mutant, $test, $started, $started / self::NANOSECONDS + $limit];
            }
        }

        self::$test = '';
        self::reap(blocking: false);
    }

    /** The run ended: the parent waits for every child, each held to its limit. */
    public static function finished(): void
    {
        if (self::$mutant !== '') {
            return;
        }

        while (self::$children !== []) {
            self::reap(blocking: true);
        }

        SchemaSwitch::splitting(null);
    }

    /** Whether this execution of a site runs its mutant: in a child forked here, yes; in the parent, never. */
    private static function reached(string $file, int $id): bool
    {
        return self::forks($file, $id) && self::forked($file, $id);
    }

    /**
     * The value a pure expression's site runs with: the original's, where
     * the stream forks nothing here, or where the mutated code gives the same
     * value, read under a handler that keeps its errors; and in a child forked
     * here, the mutated code's.
     *
     * @param Closure(): mixed $mutated
     */
    private static function changed(string $file, int $id, mixed $value, Closure $mutated): mixed
    {
        if (! self::forks($file, $id)) {
            return $value;
        }

        if (Unchanged::by($value, $mutated)) {
            return $value;
        }

        return self::forked($file, $id) ? $mutated() : $value;
    }

    /** Whether the stream forks a site's mutant at this execution. */
    private static function forks(string $file, int $id): bool
    {
        if (
            self::$deciding
            || self::$test === ''
            || ! self::$forkable
            || array_key_exists(sprintf('%s:%d', $file, $id), self::$forked)
            || ! array_key_exists($file, self::$sites)
            || ! array_key_exists($id, self::$sites[$file])
        ) {
            return false;
        }

        [$mutant, $killer] = self::$sites[$file][$id];

        return ! array_key_exists($mutant, self::$killed) && ($killer === '' || $killer === self::$test);
    }

    /** Forks a site's mutant: true in the child, which runs it, false in the parent. */
    private static function forked(string $file, int $id): bool
    {
        [$mutant, , $variables] = self::$sites[$file][$id];
        self::$deciding = true;
        self::$forked[sprintf('%s:%d', $file, $id)] = true;
        $forked = self::fork($mutant, $variables);
        self::$deciding = false;

        if ($forked) {
            SchemaSwitch::on($file, $id);
        }

        return $forked;
    }

    /**
     * Forks a child for this mutant, once a place is free: true in the child,
     * which puts the mutant in the variables its subprocesses inherit, and
     * false in the parent.
     *
     * @param array<string, string> $variables
     */
    private static function fork(string $mutant, array $variables): bool
    {
        while (count(self::$children) >= self::$most) {
            self::reap(blocking: true);
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            self::$mutant = $mutant;
            self::$children = [];
            SchemaSwitch::splitting(null);

            foreach ($variables as $name => $value) {
                putenv(sprintf('%s=%s', $name, $value));
            }

            return true;
        }

        self::$children[$pid] = [$mutant, self::$test, hrtime(as_number: true), null];

        return false;
    }

    /**
     * Takes every child that ended, writing how one that wrote nothing ended,
     * and stops each past its limit; where blocking, waits until at least one
     * ended or was stopped.
     */
    private static function reap(bool $blocking): void
    {
        do {
            $before = count(self::$children);

            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                if (array_key_exists($pid, self::$children)) {
                    [$mutant, $test] = self::$children[$pid];
                    unset(self::$children[$pid]);
                    self::endedOf($mutant, $test, $status);
                }
            }

            self::stopLate();

            if (! $blocking || count(self::$children) < $before || self::$children === []) {
                return;
            }

            usleep(self::WAIT);
        } while (true);
    }

    /**
     * Takes how a child ended: one that failed its test kills its mutant where
     * the parent passed that test, and one that did not stop itself ended in
     * its test, by an exit, a signal or a crash, which it wrote nothing of.
     */
    private static function endedOf(string $mutant, string $test, int $status): void
    {
        $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0;

        if ($signal === SIGKILL) {
            return;
        }

        if ($signal !== SIGUSR1) {
            self::write(StreamLine::endedChild($mutant, $test, pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1));
        }

        self::kill($mutant, $test);
    }

    /** A child failed this test: its mutant is killed where the parent passed it, or once the parent does. */
    private static function kill(string $mutant, string $test): void
    {
        if (! array_key_exists($test, self::$passed)) {
            self::$pending[$test][] = $mutant;

            return;
        }

        if (self::$passed[$test]) {
            self::$killed[$mutant] = true;
        }
    }

    /** Stops each child past its limit, or past the coverage map's while the parent is still in its test. */
    private static function stopLate(): void
    {
        $now = hrtime(as_number: true) / self::NANOSECONDS;

        foreach (self::$children as $pid => [$mutant, $test, $started, $deadline]) {
            $limit = $deadline ?? $started / self::NANOSECONDS + self::provisional($test);

            if ($now > $limit) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                unset(self::$children[$pid]);
                self::write(StreamLine::timedOut($mutant, $test));
            }
        }
    }

    /** A child's limit while the parent is still in its test: the map's time of that test, or the longest. */
    private static function provisional(string $test): float
    {
        return array_key_exists($test, self::$times)
            ? max(self::$floor, self::$times[$test] * self::$multiple)
            : self::$longest;
    }

    private static function write(string $line): void
    {
        file_put_contents(self::$results, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<mixed>                                                               $sites
     * @return array<string, array<int, array{string, string, array<string, string>}>>
     */
    private static function sitesOf(array $sites): array
    {
        $read = [];

        foreach ($sites as $file => $switches) {
            foreach (is_array($switches) ? $switches : [] as $id => $site) {
                if (is_array($site) && is_string($site[0] ?? null) && is_string($site[1] ?? null)) {
                    $read[(string) $file][(int) $id] = [$site[0], $site[1], self::variablesOf($site[2] ?? [])];
                }
            }
        }

        return $read;
    }

    /** @return array<string, string> */
    private static function variablesOf(mixed $variables): array
    {
        $read = [];

        foreach (is_array($variables) ? $variables : [] as $name => $value) {
            if (is_string($value)) {
                $read[(string) $name] = $value;
            }
        }

        return $read;
    }

    /**
     * @param  array<mixed>          $times
     * @return array<string, float>
     */
    private static function timesOf(array $times): array
    {
        $read = [];

        foreach ($times as $test => $seconds) {
            if (is_float($seconds) || is_int($seconds)) {
                $read[(string) $test] = (float) $seconds;
            }
        }

        return $read;
    }

    private static function number(mixed $value, float $otherwise): float
    {
        return is_float($value) || is_int($value) ? (float) $value : $otherwise;
    }
}
