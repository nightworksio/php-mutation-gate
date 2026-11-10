<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function clearstatcache;

use Closure;

use const FILE_APPEND;

use function file_put_contents;
use function filesize;
use function function_exists;
use function get_included_files;
use function getcwd;
use function hrtime;
use function is_file;
use function is_string;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function outbreak_state_digest;
use function outbreak_state_watch;
use function outbreak_touched;
use function outbreak_touches_start;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function putenv;

use const SIG_DFL;
use const SIGKILL;
use const SIGUSR1;

use function sprintf;
use function strlen;
use function substr;

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
 * Where `outbreak.so` is loaded, each fork also arms a watch on the call that
 * reached the site, in the parent and the child alike, and each writes the
 * digest of the state that call left as it returned, so the run counts the
 * forks that changed nothing (ADR-0130, H5), which changes no verdict; and
 * each child watches whether its test touches the clock, randomness, the
 * environment, another process, the network or a file outside the project
 * after the fork, so a kill by such a test is judged again by the mutant's
 * own run.
 *
 * @internal
 */
final class Stream
{
    /** This class's name under the gate's root namespace, which a watch skips the frames of. */
    private const string OWN = 'Adapter\\Pest\\Split\\Stream';

    private static ?self $stream = null;

    private string $test = '';

    private int $started = 0;

    private bool $failed = false;

    private bool $forkable = true;

    private bool $deciding = false;

    /** Whether the parent collects the lines of the current test (ADR-0135). */
    private bool $recording = false;

    /** Whether the current test raised an issue: risky, or a deprecation, notice or warning. */
    private bool $troubled = false;

    /** Whether the current test runs in a process of its own, where the parent collects none of its lines. */
    private bool $own = false;

    /** @var array<string, list<int>> every line pcov reported in each file the parent's tests loaded, executed or not */
    private array $reported = [];

    /** Whether the stream boots the application it hands each test's own process (`splitStream.bootOnce`). */
    private bool $booting = false;

    /** @var array<string, true> the mutants whose site ran while the stream booted its application, by id */
    private array $booted = [];

    /** Whether the stream handed the current test to a process forked for it, and skips it itself. */
    private bool $handed = false;

    /** Whether this process is a test's own, forked from the stream's booted application, which ends with the test. */
    private bool $testProcess = false;

    /** The mutant this process runs, in a child; none in the parent. */
    private string $mutant = '';

    /** @var array<string, true> the sites forked in the current test, by file key and switch id */
    private array $forked = [];

    /** The watch on the forked call's state, in a child; none in the parent. */
    private int $watch = 0;

    /** @var array<string, int> the watch on each forked call's state, by mutant, in the parent's current test */
    private array $watching = [];

    private function __construct(
        private readonly StreamPlan $plan,
        private readonly Children $children,
        private readonly Kills $kills,
        private readonly Descriptors $start,
    ) {
    }

    /**
     * Starts a split stream from the plan this file holds, where PHP can
     * fork; each mutant of a file the process loaded before its schema was
     * served is left for its own run.
     */
    public static function from(string $file): bool
    {
        $plan = function_exists('pcntl_fork') && function_exists('posix_kill') ? StreamPlanFile::read($file) : false;

        if ($plan === false) {
            return false;
        }

        self::$stream = new self($plan, new Children($plan), new Kills(), Descriptors::held());

        if ($plan->bootOnce && $plan->recording === '') {
            Zygote::prepared(sprintf('%s.zygote', $plan->results));
        }

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

    /** The stream starts or stops booting the application it hands each test's own process. */
    public static function booting(bool $booting): void
    {
        if (self::$stream instanceof self) {
            self::$stream->booting = $booting;
        }
    }

    /**
     * Forks the current test from the booted application: true in the
     * test's own process, which runs it; false in the stream's, which waits
     * for it, reads its kills, and skips the test; null where the stream
     * cannot fork here, and the test boots its own application.
     */
    public static function forkedTest(): ?bool
    {
        $stream = self::$stream;

        return $stream instanceof self && $stream->mutant === '' && ! $stream->testProcess && $stream->forkable
            && $stream->test !== ''
            && Descriptors::held()->shares($stream->start) === false
            ? $stream->handedOver()
            : null;
    }

    /** The test raised an issue: it was risky, or triggered a deprecation, a notice or a warning. */
    public static function troubled(): void
    {
        if (self::$stream instanceof self) {
            self::$stream->troubled = true;
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

        if ($stream->plan->recording !== '') {
            file_put_contents($stream->plan->recording, StreamLine::finished($stream->reported), FILE_APPEND | LOCK_EX);
        }

        SchemaSwitch::unsplit();
    }

    private function begin(string $test): void
    {
        $this->test = $test;
        $this->started = (int) hrtime(as_number: true);
        $this->failed = false;
        $this->troubled = false;
        $this->own = false;
        $this->handed = false;
        $this->forked = [];
        $this->watching = [];
        $this->forkable = ! Descriptors::held()->shares($this->start);
        $this->recording = $this->mutant === '' && $this->plan->recording !== '' && Recorded::start();

        if (! $this->forkable && $this->mutant === '') {
            $this->write(StreamLine::fallback($test));
        }
    }

    private function isolate(): void
    {
        $this->own = true;

        if ($this->recording) {
            $own = StreamLine::covered($this->test, [], new TestEnded(passed: false, troubled: false, own: true, seconds: 0.0));
            file_put_contents($this->plan->recording, $own, FILE_APPEND | LOCK_EX);
        }

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

        if ($this->handed) {
            $this->test = '';

            return;
        }

        $seconds = ((int) hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;

        if ($this->mutant !== '') {
            $this->stated($this->mutant, $this->watch, child: true);
            $touched = function_exists('outbreak_touched') && outbreak_touched();
            $this->write(StreamLine::child($this->mutant, $this->test, $this->failed, $seconds, $touched));
            pcntl_signal(SIGUSR1, SIG_DFL);
            posix_kill(posix_getpid(), $this->failed ? SIGUSR1 : SIGKILL);
        }

        if ($this->recording) {
            [$executed, $reported] = Recorded::stop($this->reported);
            $this->reported += $reported;
            $ended = new TestEnded(! $this->failed, $this->troubled, $this->own, $seconds);
            file_put_contents($this->plan->recording, StreamLine::covered($this->test, $executed, $ended), FILE_APPEND | LOCK_EX);
        }

        foreach ($this->watching as $mutant => $watch) {
            $this->stated($mutant, $watch, child: false);
        }

        $this->write(StreamLine::parent($this->test, ! $this->failed, $seconds));
        $this->kills->ended($this->test, ! $this->failed);
        $this->children->ended($this->test, $seconds);
        $this->test = '';
        $this->kills->failedIn($this->children->reaped(blocking: false));

        if ($this->testProcess) {
            while ($this->children->areRunning()) {
                $this->children->reaped(blocking: true);
            }

            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    /**
     * Hands the current test to a process forked for it: true in that
     * process; false in the stream's, once that process ended, with the
     * kills it wrote read, or a fallback written where it never ended the
     * test; null where PHP cannot fork.
     */
    private function handedOver(): ?bool
    {
        clearstatcache(true, $this->plan->results);
        $from = is_file($this->plan->results) ? (int) filesize($this->plan->results) : 0;
        $pid = pcntl_fork();

        if ($pid === -1) {
            return null;
        }

        if ($pid === 0) {
            $this->testProcess = true;

            return true;
        }

        pcntl_waitpid($pid, $status);
        $this->handed = true;
        $ended = HandedTest::read($this->plan->results, $from, $this->test);
        $this->kills->failedIn($ended->failed);

        if ($ended->passed === null) {
            $this->write(StreamLine::fallback($this->test));
        } else {
            $this->kills->ended($this->test, $ended->passed);
        }

        return false;
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
        if ($this->booting) {
            $this->bootedAt($file, $id);

            return false;
        }

        $site = $this->isForkable($file, $id) ? $this->plan->sites[$file][$id] : false;

        return $site !== false
            && ! $this->kills->has($site[0])
            && ! array_key_exists($site[0], $this->booted)
            && ($site[1] === '' || $site[1] === $this->test);
    }

    /** A site ran while the stream booted its application: its mutant is left to a stream that boots in every test. */
    private function bootedAt(string $file, int $id): void
    {
        $mutant = $this->plan->sites[$file][$id][0] ?? '';

        if ($mutant !== '' && ! array_key_exists($mutant, $this->booted)) {
            $this->booted[$mutant] = true;
            $this->write(StreamLine::booted($mutant));
        }
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

    /**
     * Forks a site's mutant: true in the child, which runs it, false in the
     * parent, and false where the test holds a descriptor a child would
     * share (see Descriptors), which then forks nothing more.
     */
    private function fork(string $file, int $id): bool
    {
        if (Descriptors::held()->shares($this->start)) {
            $this->isolate();

            return false;
        }

        [$mutant, , $variables] = $this->plan->sites[$file][$id];
        $this->deciding = true;
        $this->forked[sprintf('%s:%d', $file, $id)] = true;
        $watch = self::watched();
        $pid = $this->children->forked($mutant, $this->test);
        $this->deciding = false;

        if ($pid !== 0) {
            $this->watching = $watch > 0 ? [...$this->watching, $mutant => $watch] : $this->watching;

            return false;
        }

        $this->watch = $watch;
        $this->mutant = $mutant;

        if (function_exists('outbreak_touches_start')) {
            outbreak_touches_start((string) getcwd());
        }
        SchemaSwitch::unsplit();
        SchemaSwitch::on($file, $id);

        foreach ($variables as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
        }

        return true;
    }

    /**
     * A watch on the call that reached the site being forked, the nearest
     * outside the gate, where `outbreak.so` is loaded; none otherwise.
     */
    private static function watched(): int
    {
        $gate = substr(self::class, 0, -strlen(self::OWN));

        return function_exists('outbreak_state_watch') ? (int) outbreak_state_watch($gate) : 0;
    }

    /** Writes the digest of the state a watched call left, where it returned and its state could be read. */
    private function stated(string $mutant, int $watch, bool $child): void
    {
        $digest = $watch > 0 && function_exists('outbreak_state_digest') ? outbreak_state_digest($watch) : null;

        if (is_string($digest)) {
            $this->write(StreamLine::state($mutant, $this->test, $child, $digest));
        }
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->results, $line, FILE_APPEND | LOCK_EX);
    }
}
