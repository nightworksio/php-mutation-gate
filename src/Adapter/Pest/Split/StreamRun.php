<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function clearstatcache;

use Closure;

use function dirname;
use function filesize;
use function get_included_files;
use function hrtime;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
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
 * other may be served a schema whose sites it decides; where it reconnects,
 * the database classes Copies runs, which it runs only where it forks at
 * none of their sites.
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
 * Where the plan reconnects (ADR-0147), a test that holds descriptors the
 * stream did not hold as it started forks where Copies takes a copy of each
 * database the application holds open, and the child points the
 * application at its copies before its mutant turns on.
 *
 * @internal
 */
final class StreamRun
{
    private string $test = '';

    private int $started = 0;

    private bool $failed = false;

    private bool $forkable = true;

    private bool $deciding = false;

    /** Whether the current test raised an issue: risky, or a deprecation, notice or warning. */
    private bool $troubled = false;

    /** Whether the stream handed the current test to a process forked for it, and skips it itself. */
    private bool $handed = false;

    /** Whether this process is a test's own, forked from the stream's booted application, which ends with the test. */
    private bool $testProcess = false;

    /** The mutant this process runs, in a child; none in the parent. */
    private string $mutant = '';

    /** @var array<string, true> the sites forked in the current test, by file key and switch id */
    private array $forked = [];

    private readonly ForkStates $states;

    private readonly ParentLines $lines;

    private readonly BootedSites $boot;

    private function __construct(
        private readonly StreamPlan $plan,
        private readonly Children $children,
        private readonly Kills $kills,
        private readonly Descriptors $start,
    ) {
        $this->states = new ForkStates($plan);
        $this->lines = new ParentLines($plan);
        $this->boot = new BootedSites($plan);
    }

    /**
     * A split stream started from this plan; each mutant of a file the
     * process loaded before its schema was served is left for its own run.
     */
    public static function of(StreamPlan $plan): self
    {
        $run = new self($plan, new Children($plan), new Kills(), Descriptors::held());

        if ($plan->bootOnce && $plan->recording === '') {
            Zygote::prepared(sprintf('%s.zygote', $plan->results));
        }

        foreach ($plan->unservedIn(get_included_files()) as $mutant) {
            $plan->recorded(StreamLine::unserved($mutant));
        }

        SchemaSwitch::splitting(
            static fn(string $file, int $id): bool => $run->forks($file, $id) && $run->fork($file, $id),
            $run->changed(...),
        );

        return $run;
    }

    /** The test failed or errored, in this process. */
    public function fail(): void
    {
        $this->failed = true;
    }

    /** The test raised an issue: it was risky, or triggered a deprecation, a notice or a warning. */
    public function trouble(): void
    {
        $this->troubled = true;
    }

    /** The stream starts or stops booting the application it hands each test's own process. */
    public function booting(bool $booting): void
    {
        $this->boot->booting($booting);
    }

    /**
     * Forks the current test from the booted application, where the stream
     * can fork here: taken in the test's own process, which runs it, and
     * waited for in the stream's, which reads its kills and skips the test.
     */
    public function forkedTest(): TestHanding
    {
        return $this->mutant === '' && ! $this->testProcess && $this->forkable && $this->test !== ''
            && ! Descriptors::held()->shares($this->start)
            ? $this->handedOver()
            : TestHanding::Kept;
    }

    /** The run ended: the parent waits for every child, each held to its limit. */
    public function finished(): void
    {
        if ($this->mutant !== '') {
            return;
        }

        $this->kills->failedIn($this->children->reapedAll());
        $this->lines->finished();
        SchemaSwitch::unsplit();
    }

    /** A test begins: the sites it reaches are its own, and none of them is forked yet. */
    public function began(string $test): void
    {
        $this->test = $test;
        $this->started = (int) hrtime(as_number: true);
        $this->failed = false;
        $this->troubled = false;
        $this->handed = false;
        $this->forked = [];
        $this->states->began();
        $this->forkable = $this->plan->reconnect || ! Descriptors::held()->shares($this->start);
        $this->lines->began(parent: $this->mutant === '');

        if (! $this->forkable && $this->mutant === '') {
            $this->write(StreamLine::fallback($test));
        }
    }

    /** The test runs in a process of its own, where no fork reaches it. */
    public function isolated(): void
    {
        $this->lines->isolated($this->test);

        if ($this->forkable && $this->mutant === '') {
            $this->write(StreamLine::fallback($this->test));
        }

        $this->forkable = false;
    }

    /**
     * The test ended. A child writes how it ended and stops at once; the
     * parent writes how its own ended and holds each child of it to its
     * limit.
     */
    public function ended(): void
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
            $this->childEnded($seconds);
        }

        $this->lines->ended($this->test, ! $this->failed, $this->troubled, $seconds);
        $this->states->parentEnded($this->test);
        $this->write(StreamLine::parent($this->test, ! $this->failed, $seconds));
        $this->kills->ended($this->test, ! $this->failed);
        $this->children->ended($this->test, $seconds);
        $this->test = '';
        $this->kills->failedIn($this->children->reaped(blocking: false));

        if ($this->testProcess) {
            $this->children->reapedAll();
            OwnEnd::by(SIGKILL);
        }
    }

    /** A child's test ended: it writes how, and stops at once, by the signal that says whether it failed. */
    private function childEnded(float $seconds): void
    {
        $this->states->childEnded($this->mutant, $this->test);
        $touched = $this->states->touched();
        $this->write(StreamLine::child($this->mutant, $this->test, $this->failed, $seconds, $touched));
        pcntl_signal(SIGUSR1, SIG_DFL);
        OwnEnd::by($this->failed ? SIGUSR1 : SIGKILL);
    }

    /**
     * Hands the current test to a process forked for it: true in that
     * process; false in the stream's, once that process ended, with the
     * kills it wrote read, or a fallback written where it never ended the
     * test; none where PHP cannot fork.
     */
    private function handedOver(): TestHanding
    {
        clearstatcache(clear_realpath_cache: true, filename: $this->plan->results);
        $from = is_file($this->plan->results) ? (int) filesize($this->plan->results) : 0;
        $pid = pcntl_fork();

        if ($pid === -1) {
            return TestHanding::Kept;
        }

        if ($pid === 0) {
            $this->testProcess = true;

            return TestHanding::Taken;
        }

        pcntl_waitpid($pid, $status);
        $this->handed = true;
        HandedTest::read($this->plan->results, $from, $this->test)->into($this->kills, $this->plan);

        return TestHanding::Waited;
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
        if ($this->boot->areBooting()) {
            $this->boot->ran($file, $id);

            return false;
        }

        $site = $this->isForkable($file, $id) ? $this->plan->sites[$file][$id] : false;

        return $site !== false
            && ! $this->kills->has($site[0])
            && ! $this->boot->holds($site[0])
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

    /**
     * Forks a site's mutant: true in the child, which runs it, false in the
     * parent, and false where the test holds a descriptor a child would
     * share (see Descriptors) and no copies stand in for it, which then
     * forks nothing more.
     */
    private function fork(string $file, int $id): bool
    {
        $this->deciding = true;
        $copies = Copies::atFork($this->start, $this->plan->reconnect, dirname($this->plan->results));

        if (! $copies instanceof Copies) {
            $this->deciding = false;
            $this->isolated();

            return false;
        }

        [$mutant, , $variables] = $this->plan->sites[$file][$id];
        $this->forked[sprintf('%s:%d', $file, $id)] = true;
        $watch = $this->states->armed();
        $pid = $this->children->forked($mutant, $this->test, $copies);
        $this->deciding = false;

        if ($pid !== 0) {
            $this->states->forked($mutant, $watch);

            return false;
        }

        $this->states->forkedInto($watch);
        $this->mutant = $mutant;
        SchemaSwitch::unsplit();
        $copies->reconnected();
        SchemaSwitch::on($file, $id);

        foreach ($variables as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
        }

        return true;
    }

    private function write(string $line): void
    {
        $this->plan->recorded($line);
    }
}
