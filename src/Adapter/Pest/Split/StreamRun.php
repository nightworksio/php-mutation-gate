<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use Closure;

use function dirname;
use function get_included_files;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\ServedChildren;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
use NightWorksIO\MutationGate\Core\NotGiven;

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
    private CurrentTest $test;

    private bool $deciding = false;

    /** Whether this process is a test's own, forked from the stream's booted application, which ends with the test. */
    private bool $testProcess = false;

    /** The mutant this process runs, in a child; none in the parent. */
    private string $mutant = '';

    private readonly ForkFallbacks $fallbacks;

    private readonly ForkStates $states;

    private readonly ParentLines $lines;

    private readonly BootedSites $boot;

    private readonly StartedProcesses $processes;

    private readonly ParentDeadline $deadline;

    private function __construct(
        private readonly StreamPlan $plan,
        private readonly Children $children,
        private readonly Kills $kills,
        private readonly Descriptors $start,
    ) {
        $this->test = CurrentTest::none();
        $this->states = new ForkStates($plan);
        $this->lines = new ParentLines($plan, Recorded::inProcess());
        $this->boot = new BootedSites($plan);
        $this->processes = StartedProcesses::beside($plan->results);
        $this->fallbacks = new ForkFallbacks($plan, $this->lines);
        $this->deadline = ParentDeadline::of($plan, $plan->places->mark());
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
        $this->test->fail();
    }

    /** The test raised an issue: it was risky, or triggered a deprecation, a notice or a warning. */
    public function trouble(): void
    {
        $this->test->trouble();
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
        return $this->mutant === '' && ! $this->testProcess && $this->test->isForkable()
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
        $this->processes->unwatched();
        SchemaSwitch::unsplit();
    }

    /** A test begins: the sites it reaches are its own, and none of them is forked yet. */
    public function began(string $test): void
    {
        $held = Descriptors::held()->shares($this->start);
        $this->test = new CurrentTest($test, $this->plan->reconnect || ! $held, $held);
        $this->states->began();
        $this->lines->began(parent: $this->mutant === '');

        $this->processes->watched();
        $this->fallbacks->began($this->test);

        if ($this->isParent()) {
            $this->deadline->began($test);
        }
    }

    /** The test runs in a process of its own, where no fork reaches it. */
    public function isolated(): void
    {
        $this->fallbacks->isolated($this->test, parent: $this->mutant === '');
    }

    /**
     * The test says no fork may judge it from here, as it reaches what a
     * child would share with the stream: a child forked in it leaves its
     * mutant to its own run of the test and stops at once; the stream forks
     * nothing more in it.
     */
    public function unforkable(): void
    {
        if ($this->mutant !== '') {
            $this->write(StreamLine::fallback($this->test->id, $this->mutant));
            OwnEnd::by(SIGKILL);
        }

        $this->isolated();
    }

    /**
     * The test ended. A child writes how it ended and stops at once; the
     * parent writes how its own ended and holds each child of it to its
     * limit.
     */
    public function ended(): void
    {
        $test = $this->test;

        if (! $test->isRunning()) {
            return;
        }

        if ($this->isParent()) {
            $this->deadline->ended(max(0.0, $test->seconds() - $this->children->waited()));
        }

        if ($test->wasHanded()) {
            $this->test = CurrentTest::none();

            return;
        }

        $seconds = $test->seconds();

        if ($this->mutant !== '') {
            $this->childEnded($seconds);
        }

        $this->processes->whereRan($this->isolated(...));
        $this->lines->ended($test->id, $test->passed(), $test->isTroubled(), $seconds);
        $this->states->parentEnded($test->id);
        $this->write(StreamLine::parent($test->id, $test->passed(), $seconds));
        $this->kills->ended($test->id, $test->passed());
        $this->children->ended($test->id, $seconds);
        $this->test = CurrentTest::none();
        $this->kills->failedIn($this->children->reaped(blocking: false));

        if ($this->testProcess) {
            $this->children->reapedAll();
            OwnEnd::by(SIGKILL);
        }
    }

    /** A child's test ended: it writes how, and stops at once, by the signal that says whether it failed. */
    private function childEnded(float $seconds): void
    {
        $failed = ! $this->test->passed();
        $this->states->childEnded($this->mutant, $this->test->id);
        $touched = $this->states->touched();
        $this->write(StreamLine::child($this->mutant, $this->test->id, $failed, $seconds, $touched));
        pcntl_signal(SIGUSR1, SIG_DFL);
        OwnEnd::by($failed ? SIGUSR1 : SIGKILL);
    }

    /**
     * Hands the current test to a process forked for it: true in that
     * process; false in the stream's, once that process ended, with the
     * kills it wrote read, or a fallback written where it never ended the
     * test; none where PHP cannot fork.
     */
    private function handedOver(): TestHanding
    {
        $from = HandedTest::from($this->plan->results);
        $pid = pcntl_fork();

        if ($pid === -1) {
            return TestHanding::Kept;
        }

        if ($pid === 0) {
            $this->testProcess = true;

            return TestHanding::Taken;
        }

        pcntl_waitpid($pid, $status);
        $this->test->hand();
        HandedTest::read($this->plan->results, $from, $this->test->id)->into($this->kills, $this->plan);

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

    /**
     * Whether the stream forks a site's mutant at this execution: at any
     * test where it names no likely killer, its killer is a test the stream
     * fell back on, or a test held a descriptor at its site; otherwise at
     * that killer.
     */
    private function forks(string $file, int $id): bool
    {
        if ($this->boot->areBooting()) {
            $this->boot->ran($file, $id);
        }

        $site = $this->isForkable($file, $id) ? $this->plan->sites[$file][$id] : false;

        return $site !== false
            && ! $this->kills->has($site[0])
            && ! $this->boot->holds($site[0])
            && $this->fallbacks->forks($site[0], $site[1], $this->test->id);
    }

    /** Whether the stream may fork at a site of the plan this execution reaches, not yet forked in this test. */
    private function isForkable(string $file, int $id): bool
    {
        return ! $this->deciding
            && $this->test->isForkable()
            && array_key_exists($file, $this->plan->sites)
            && array_key_exists($id, $this->plan->sites[$file])
            && ! $this->test->hasForkedAt($file, $id);
    }

    /**
     * Forks a site's mutant: true in the child, which runs it, false in the
     * parent; and false where the test started a PHP process that ran
     * without the child's mutant (see StartedProcesses), or holds a
     * descriptor a child would share (see Descriptors) and no copies stand
     * in for it (see ForkFallbacks).
     */
    private function fork(string $file, int $id): bool
    {
        $this->deciding = true;
        $copies = $this->processes->ran()
            ? NotGiven::value()
            : Copies::atFork($this->start, $this->plan->reconnect, dirname($this->plan->results));

        if (! $copies instanceof Copies) {
            $this->deciding = false;
            [$mutant] = $this->plan->sites[$file][$id];
            $this->fallbacks->unforked($this->test, $this->processes->ran(), $file, $id, $mutant);

            return false;
        }

        [$mutant, , $variables] = $this->plan->sites[$file][$id];
        $this->test->forkAt($file, $id);
        $watch = $this->states->armed();
        $waited = $this->children->waited();
        $pid = $this->children->forked($mutant, $this->test->id, $copies, $this->test->seconds());
        $this->deciding = false;

        if ($pid !== 0) {
            $this->deadline->waited($this->children->waited() - $waited);
            $this->states->forked($mutant, $watch);

            return false;
        }

        $this->states->forkedInto($watch);
        $this->mutant = $mutant;
        $this->processes->unwatched();
        SchemaSwitch::unsplit();
        $copies->reconnected();
        SchemaSwitch::on($file, $id);

        foreach ($variables as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
        }

        ServedChildren::tellOf($variables);

        return true;
    }

    /** Whether this process is the stream's own, neither a child nor a test's own process. */
    private function isParent(): bool
    {
        return $this->mutant === '' && ! $this->testProcess;
    }

    private function write(string $line): void
    {
        $this->plan->recorded($line);
    }
}
