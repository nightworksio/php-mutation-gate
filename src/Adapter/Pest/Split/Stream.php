<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use Closure;

use const FILE_APPEND;

use function file_put_contents;
use function function_exists;
use function get_included_files;
use function hrtime;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Core\Time\Seconds;

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

    private function __construct(
        private readonly StreamPlan $plan,
        private readonly Children $children,
        private readonly Kills $kills,
        private readonly int $sockets,
    ) {
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

        self::$stream = new self($plan, new Children($plan), new Kills(), Sockets::open());

        foreach ($plan->unservedIn(get_included_files()) as $mutant) {
            self::$stream->write(StreamLine::unserved($mutant));
        }

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
            $stream->kills->failedIn($stream->children->reaped(blocking: true));
        }

        SchemaSwitch::unsplit();
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

        $seconds = ((int) hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;

        if ($this->mutant !== '') {
            $this->write(StreamLine::child($this->mutant, $this->test, $this->failed, $seconds));
            pcntl_signal(SIGUSR1, SIG_DFL);
            posix_kill(posix_getpid(), $this->failed ? SIGUSR1 : SIGKILL);
        }

        $this->write(StreamLine::parent($this->test, ! $this->failed, $seconds));
        $this->kills->ended($this->test, ! $this->failed);
        $this->children->ended($this->test, $seconds);
        $this->test = '';
        $this->kills->failedIn($this->children->reaped(blocking: false));
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
        $site = $this->isForkable($file, $id) ? $this->plan->sites[$file][$id] : false;

        return $site !== false
            && ! $this->kills->has($site[0])
            && ($site[1] === '' || $site[1] === $this->test);
    }

    /** Whether the stream may fork at a site of the plan this execution reaches, not yet forked in this test. */
    private function isForkable(string $file, int $id): bool
    {
        return ! $this->deciding
            && $this->test !== ''
            && $this->forkable
            && array_key_exists($file, $this->plan->sites)
            && array_key_exists($id, $this->plan->sites[$file])
            && ! array_key_exists(sprintf('%s:%d', $file, $id), $this->forked);
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
        SchemaSwitch::unsplit();
        SchemaSwitch::on($file, $id);

        foreach ($variables as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
        }

        return true;
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->results, $line, FILE_APPEND | LOCK_EX);
    }
}
