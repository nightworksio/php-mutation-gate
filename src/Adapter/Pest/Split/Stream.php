<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function array_map;

use Closure;

use const FILE_APPEND;

use function file_put_contents;
use function function_exists;
use function get_included_files;
use function hrtime;
use function in_array;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;

use function pcntl_signal;
use function posix_getpid;
use function posix_kill;
use function putenv;

use const SIG_DFL;
use const SIGKILL;
use const SIGUSR1;

use function sprintf;

/**
 * A split stream (ADR-0117): the unmutated suite runs once, and where a test
 * first reaches the site of a mutant still undecided, the process forks. The
 * child turns that mutant on, finishes only that test and writes how it
 * ended; the parent goes on, and writes how each of its own tests ended,
 * which judges every child of that test.
 *
 * It loads no class of the gate but its own and SchemaSwitch, as every
 * other may be served a schema whose sites it decides.
 *
 * @internal
 */
final class Stream
{
    /** Nanoseconds in a second. */
    private const float NANOSECONDS = 1e9;

    private static ?self $stream = null;

    private string $test = '';

    private int $started = 0;

    private bool $failed = false;

    private bool $forkable = true;

    private bool $deciding = false;

    /** The mutant this process runs, in a child; none in the parent. */
    private string $mutant = '';

    /** @var array<string, true> the sites forked in the current test, by file key and switch id */
    private array $forked = [];

    /** @var array<string, true> the mutants a child killed in a test the parent passed, by id */
    private array $killed = [];

    /** @var array<string, bool> whether the parent passed each test it ended, by the test's id */
    private array $passed = [];

    /** @var array<string, list<string>> the mutants a child failed, by the test the parent is still in */
    private array $pending = [];

    private function __construct(private readonly StreamPlan $plan, private readonly Children $children, private int $sockets)
    {
    }

    /**
     * Starts a split stream from the plan this file holds, where PHP can
     * fork; each mutant of a file the process loaded before its schema was
     * served is left for its own run.
     */
    public static function from(string $file): bool
    {
        $plan = function_exists('pcntl_fork') && function_exists('posix_kill') ? StreamPlan::read($file) : false;

        if ($plan === false) {
            return false;
        }

        self::$stream = new self($plan, new Children($plan), Sockets::open());
        self::$stream->unserved(get_included_files());
        SchemaSwitch::splitting(self::$stream->reached(...), self::$stream->changed(...));

        return true;
    }

    /** A test begins: the sites it reaches are its own, and none of them is forked yet. */
    public static function began(string $test): void
    {
        self::$stream?->begin($test);
    }

    /** The test failed or errored, in this process. */
    public static function failed(): void
    {
        if (self::$stream instanceof self) {
            self::$stream->failed = true;
        }
    }

    /** The test runs in a process of its own, where no fork reaches it. */
    public static function isolated(): void
    {
        self::$stream?->isolate();
    }

    /**
     * The test ended. A child writes how it ended and stops at once; the
     * parent writes how its own ended and holds each child of it to its
     * limit.
     */
    public static function ended(): void
    {
        self::$stream?->end();
    }

    /** The run ended: the parent waits for every child, each held to its limit. */
    public static function finished(): void
    {
        $stream = self::$stream;

        if (! $stream instanceof self || $stream->mutant !== '') {
            return;
        }

        while ($stream->children->areRunning()) {
            $stream->killedBy($stream->children->reaped(blocking: true));
        }

        SchemaSwitch::splitting(null);
    }

    /** @param list<string> $loaded */
    private function unserved(array $loaded): void
    {
        foreach ($this->plan->paths as $key => $path) {
            if (! in_array($path, $loaded, strict: true) || ! array_key_exists($key, $this->plan->sites)) {
                continue;
            }

            foreach ($this->plan->sites[$key] as [$mutant]) {
                $this->write(StreamLine::unserved($mutant));
            }
        }
    }

    private function begin(string $test): void
    {
        $this->test = $test;
        $this->started = (int) hrtime(as_number: true);
        $this->failed = false;
        $this->forked = [];
        $this->forkable = Sockets::open() <= $this->sockets;

        if (! $this->forkable && $this->mutant === '') {
            $this->write(StreamLine::fallback($test));
        }
    }

    private function isolate(): void
    {
        if ($this->forkable && $this->mutant === '') {
            $this->write(StreamLine::fallback($this->test));
        }

        $this->forkable = false;
    }

    private function end(): void
    {
        if ($this->test === '') {
            return;
        }

        $seconds = ((int) hrtime(as_number: true) - $this->started) / self::NANOSECONDS;

        if ($this->mutant !== '') {
            $this->write(StreamLine::child($this->mutant, $this->test, $this->failed, $seconds));
            pcntl_signal(SIGUSR1, SIG_DFL);
            posix_kill(posix_getpid(), $this->failed ? SIGUSR1 : SIGKILL);
        }

        $this->write(StreamLine::parent($this->test, ! $this->failed, $seconds));
        $this->passed[$this->test] = ! $this->failed;
        $pending = array_key_exists($this->test, $this->pending) ? $this->pending[$this->test] : [];
        unset($this->pending[$this->test]);
        $test = $this->test;
        $this->killedBy(array_map(static fn(string $mutant): array => [$mutant, $test], $pending));
        $this->children->ended($this->test, $seconds);
        $this->test = '';
        $this->killedBy($this->children->reaped(blocking: false));
    }

    /** Whether this execution of a site runs its mutant: in a child forked here, yes; in the parent, never. */
    private function reached(string $file, int $id): bool
    {
        return $this->forks($file, $id) && $this->fork($file, $id);
    }

    /**
     * The value a pure expression's site runs with: the original's, where
     * the stream forks nothing here, or where the mutated code gives the same
     * value; and in a child forked here, the mutated code's.
     *
     * @param Closure(): mixed $mutated
     */
    private function changed(string $file, int $id, mixed $value, Closure $mutated): mixed
    {
        return $this->forks($file, $id) && ! Unchanged::by($value, $mutated) && $this->fork($file, $id)
            ? $mutated()
            : $value;
    }

    /** Whether the stream forks a site's mutant at this execution. */
    private function forks(string $file, int $id): bool
    {
        $site = ! $this->deciding && $this->test !== '' && $this->forkable && array_key_exists($file, $this->plan->sites)
            && ! array_key_exists(sprintf('%s:%d', $file, $id), $this->forked)
            && array_key_exists($id, $this->plan->sites[$file]) ? $this->plan->sites[$file][$id] : false;

        return $site !== false
            && ! array_key_exists($site[0], $this->killed)
            && ($site[1] === '' || $site[1] === $this->test);
    }

    /** Forks a site's mutant: true in the child, which runs it, false in the parent. */
    private function fork(string $file, int $id): bool
    {
        [$mutant, , $variables] = $this->plan->sites[$file][$id];
        $this->deciding = true;
        $this->forked[sprintf('%s:%d', $file, $id)] = true;
        $pid = $this->children->forked($mutant, $this->test);
        $this->deciding = false;

        if ($pid !== 0) {
            return false;
        }

        $this->mutant = $mutant;
        SchemaSwitch::splitting(null);
        SchemaSwitch::on($file, $id);

        foreach ($variables as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
        }

        return true;
    }

    /**
     * Children failed or ended in these tests: each mutant is killed where the
     * parent passed its test, or once the parent does.
     *
     * @param list<array{string, string}> $failed
     */
    private function killedBy(array $failed): void
    {
        foreach ($failed as [$mutant, $test]) {
            if (! array_key_exists($test, $this->passed)) {
                $this->pending[$test][] = $mutant;

                continue;
            }

            if ($this->passed[$test]) {
                $this->killed[$mutant] = true;
            }
        }
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->results, $line, FILE_APPEND | LOCK_EX);
    }
}
