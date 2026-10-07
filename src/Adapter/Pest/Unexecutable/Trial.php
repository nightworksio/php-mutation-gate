<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\ServedOriginal;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * Runs the tests that judge a mutant of a line that is not executable, with
 * Pest's override serving the mutated copy in place of the original, as Pest
 * serves its own mutants, the trials of several mutants side by side, each
 * run told its place and writing in a directory of its own. The tests must
 * pass on their own first, once for each set of them and file they judge,
 * with that file served unmutated through the same override, so a test that
 * fails only while the override serves a file fails there too: where they
 * run out of the limit there, the mutant is skipped as too slow to run, and
 * where they fail, it is left unjudged. Where they pass, the time they took
 * there, as the JUnit log of that run says, is what timeout triage weighs a
 * mutant of theirs that runs out of its limit against. A run that loaded the
 * original before the override, never loaded it, or ran where opcache could
 * serve a cached original, judges nothing, and the reason says what the run
 * did (see Evidence), from what it printed and the JUnit log it writes beside
 * the guard. A kill names as its killer the first test that log says
 * failed or errored, or every one under a full kill matrix (see
 * JUnitKillers), and gives how its run ended where it names none (ADR-0014,
 * decision 17).
 */
final class Trial
{
    private const string ALONE = 'the selected tests fail on their own';

    private const string UNGUARDED = 'the run wrote no guard, so the gate cannot tell the mutated file ran';

    private const string BEFORE = 'loaded before the override';

    private const string NEVER = 'never loaded';

    private const string OPCACHE = '%s or %s on';

    /** A reason, then what the run did. */
    private const string SAID = '%s (%s)';

    /** The directory of a run in a batch, by its position there. */
    private const string POSITION = '%s/%d';

    /** The guard a run writes, in its directory. */
    private const string GUARD = 'guard.json';

    /**
     * @var array<string, Outcome|Seconds|Unmeasured> each set of test files' outcome on its own for the file they
     *                                                judge, where they fail, or the time they took there, where
     *                                                they pass, by the file and their paths (see TrialRun::set())
     */
    private array $alone = [];

    /**
     * @param string $directory where each run of a batch writes its guard and JUnit log, in a directory named by
     *                          its position in the batch
     */
    public function __construct(
        private readonly Project $project,
        private readonly Shell $shell,
        private readonly Invocation $invocation,
        private readonly WholeSuite|Group $judgedBy,
        private readonly Withheld $withheld,
        private readonly string $directory,
        private readonly MemoryScan $scan,
        private readonly WorkerSlots $slots,
        private readonly MatrixKind $matrix = MatrixKind::FirstKiller,
    ) {
    }

    /**
     * What the tests in some files find of a mutant whose mutated copy of a
     * file is kept at a path, each of their runs allowed this long.
     */
    public function of(Paths $tests, Path $original, string $copy, Seconds $limit): Outcome
    {
        $outcomes = $this->ofEach(TrialRun::of($tests, $original, $copy, $limit));

        return $outcomes[0];
    }

    /**
     * What each trial's tests find, their runs side by side: first each set
     * of tests not run on its own before, then the trials whose tests pass on
     * their own. The outcomes are in the order the trials were given.
     *
     * @return list<Outcome>
     */
    public function ofEach(TrialRun ...$trials): array
    {
        $trials = array_values($trials);
        $this->aloneEach($trials);
        $outcomes = [];
        $runs = [];
        $needs = [];

        foreach ($trials as $at => $trial) {
            $alone = $this->alone[$trial->set()];

            if ($alone instanceof Outcome) {
                $outcomes[$at] = $alone->within($trial->limit());

                continue;
            }

            $runs[$at] = $this->mutated($trial, count($runs));
            $needs[$at] = $alone;
        }

        $ends = $runs === []
            ? []
            : [...$this->shell->sideBySide($this->slots, Unlimited::time(), ...array_values($runs))];

        foreach (array_keys($runs) as $position => $at) {
            $outcomes[$at] = $this->found($trials[$at], $ends[$position], $position)->needing($needs[$at]);
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Each set of tests these trials run that has not run on its own for the
     * file they mutate, run side by side with that file served unmutated
     * through Pest's override, as each mutated copy is served (see
     * ServedOriginal), its outcome kept where it fails or runs out of time
     * there, or where the file cannot be served.
     *
     * @param list<TrialRun> $trials
     */
    private function aloneEach(array $trials): void
    {
        $sets = [];

        foreach ($trials as $trial) {
            $sets += array_key_exists($trial->set(), $this->alone) ? [] : [$trial->set() => $trial];
        }

        $commands = [];
        $running = [];

        foreach ($sets as $set => $trial) {
            $served = ServedOriginal::of($this->project, $this->directory, $trial->original());

            if ($served instanceof CannotJudge) {
                $this->alone[$set] = Outcome::unjudged(sprintf(self::SAID, self::ALONE, $served->why()));

                continue;
            }

            $this->project->without($this->logOf(count($running)));
            $commands[] = $served->onto($this->judging($trial->tests(), $trial->limit(), count($running)));
            $running[] = $trial;
        }

        $ends = $commands === [] ? [] : [...$this->shell->sideBySide($this->slots, Unlimited::time(), ...$commands)];

        foreach ($running as $position => $trial) {
            $ran = $ends[$position];
            $this->alone[$trial->set()] = match (true) {
                $ran->succeeded() => TestTimes::in($this->logOf($position)),
                $ran->wasStopped() => $this->skipped($ran, $trial->limit()),
                default => $this->unjudged(self::ALONE, $ran, $trial->tests(), $position),
            };
        }
    }

    /**
     * A mutant whose tests on their own ran out of its limit, which no run
     * of the mutant within that limit can judge, with how long they took:
     * the limit, where the run was stopped untimed.
     */
    private function skipped(Ran $ran, Seconds $limit): Outcome
    {
        $took = $ran->duration();

        return Outcome::skipped()->took($took instanceof Seconds ? $took : $limit);
    }

    /** The run of a trial with the mutated copy served in place of its original, at a position in the batch. */
    private function mutated(TrialRun $trial, int $position): Command
    {
        $this->project->without($this->guardOf($position), $this->logOf($position));

        return $this->judging($trial->tests(), $trial->limit(), $position)->with([
            Recorder::MUTANT => $this->project->absolute($trial->original()),
            Recorder::MUTATED => $trial->copy(),
            GateVariable::Guard->value => $this->guardOf($position),
        ]);
    }

    /** What a trial's run found, by how it ended and the guard it wrote. */
    private function found(TrialRun $trial, Ran $ran, int $position): Outcome
    {
        $took = $ran->duration();
        $outcome = $ran->wasStopped() ? Outcome::timedOut() : $this->guarded($ran, $trial, $position);

        return $outcome->took($took instanceof Seconds ? $took : Seconds::of(0.0))->within($trial->limit());
    }

    private function judging(Paths $tests, Seconds $limit, int $position): Command
    {
        $log = sprintf('%s=%s', PhpUnitOption::LogJunit->value, $this->logOf($position));

        return $this->scan->onto($this->invocation->judging($tests, $this->judgedBy, $this->withheld, $log)
            ->within($limit));
    }

    /** Where a run at a position in the batch writes its files, made where it is not there. */
    private function directoryOf(int $position): string
    {
        $directory = sprintf(self::POSITION, $this->directory, $position);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        return $directory;
    }

    /** The guard a run at a position in the batch writes. */
    private function guardOf(int $position): string
    {
        return sprintf('%s/%s', $this->directoryOf($position), self::GUARD);
    }

    /**
     * The tests the JUnit log of a run at a position in the batch says failed
     * or errored: the first of them, or every one where the run records a full
     * kill matrix, as Pest's own mutants name theirs.
     */
    private function killersAt(int $position): TestIds
    {
        $killers = [...JUnitKillers::in($this->logOf($position))];

        return TestIds::of(...$this->matrix === MatrixKind::Full ? $killers : array_slice($killers, 0, 1));
    }

    /** The JUnit log a run at a position in the batch writes, beside its guard. */
    private function logOf(int $position): string
    {
        return sprintf('%s/%s', $this->directoryOf($position), JUnitLog::NAME);
    }

    /**
     * What a run that finished found, where its guard says the mutated copy is
     * what ran. A run a signal ended writes no guard, and kills the mutant:
     * its tests passed on their own, so only the mutated copy ended it.
     */
    private function guarded(Ran $ran, TrialRun $trial, int $position): Outcome
    {
        $guard = $this->guardOf($position);
        $text = is_file($guard) ? file_get_contents($guard) : false;

        return match (true) {
            is_string($text) => $this->read(Node::decode($text), $ran, $trial, $position),
            $ran->endedBySignal() => Outcome::killed($this->killersAt($position), Ended::ofRun($ran)),
            default => $this->unjudged(self::UNGUARDED, $ran, $trial->tests(), $position),
        };
    }

    private function read(Node $seen, Ran $ran, TrialRun $trial, int $position): Outcome
    {
        $tests = $trial->tests();

        try {
            return match (true) {
                $seen->field('before')->boolean() => $this->unjudged(self::BEFORE, $ran, $tests, $position),
                $seen->field('opcache')->boolean() => $this->unjudged(
                    sprintf(self::OPCACHE, Opcache::CLI, Opcache::FILE_CACHE),
                    $ran,
                    $tests,
                    $position,
                ),
                ! $seen->field('loaded')->boolean() => $this->unjudged(self::NEVER, $ran, $tests, $position),
                $ran->succeeded() => Outcome::survived(),
                default => Outcome::killed($this->killersAt($position), Ended::ofRun($ran)),
            };
        } catch (NotInShape) {
            return $this->unjudged(self::UNGUARDED, $ran, $tests, $position);
        }
    }

    /** A mutant left unjudged for a reason, which says what the run at a position in the batch did. */
    private function unjudged(string $reason, Ran $ran, Paths $tests, int $position): Outcome
    {
        $evidence = Evidence::of($ran, FailedFirst::in($this->logOf($position)), $tests);

        return Outcome::unjudged(sprintf(self::SAID, $reason, $evidence->text()));
    }
}
