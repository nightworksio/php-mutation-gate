<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;

use function spl_object_id;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The silence limit of each mutant's own run, kept in Pest's parent
 * process (ADR-0008): a run no test of which has finished for
 * its limit since its tests began is stopped, as one at its whole limit is,
 * and the stop is recorded by the mutant's mutated copy. Each own run tells
 * of its progress on its error output (see Recording\Heartbeat): once as
 * its tests begin, which its loading before is not held to, as each test
 * starts and as each finishes. A run whose limit is none is never stopped
 * for silence. A test the coverage map timed is held to a limit of its own
 * as well, where the gate names one (ADR-0090), and a run whose test runs
 * past it is stopped and recorded so.
 *
 * @internal
 */
final class Silence
{
    /** What an own run writes on its error output as its tests begin and as each finishes. */
    public const string BEAT = TestEvents::BEAT;

    /** @var array<int, WatchedRun> each run watched, by its process */
    private static array $watched = [];

    /**
     * Watches an own run, its covering tests named by their ids as the
     * coverage map names them, by its mutated copy and its mutator's class,
     * where the run passes this filter of them. A run whose filter was left
     * out runs every test it loads, of which the slowest is not known, so it
     * is held to no silence limit, only each timed test to its own.
     * Whatever was watched under its process's id before is forgotten, since
     * PHP gives a freed process's id to the next.
     *
     * @param list<string> $tests
     */
    public static function watch(Process $process, array $tests, string $mutated, string $filter, string $mutator): void
    {
        $silence = Selection::passable($filter) ? MutantTime::silence($tests, $mutator) : NotGiven::value();
        $run = WatchedRun::of($silence, $mutated, MutantTime::watch());
        unset(self::$watched[spl_object_id($process)]);

        if ($run->isWatched()) {
            self::$watched[spl_object_id($process)] = $run;
        }
    }

    /**
     * Stops a run no test of which has finished for its silence limit, or
     * whose test has run past its own, as it stands at these seconds of the
     * clock: its time is cut to less than it has run, so Symfony's Process
     * stops it and says it ran out of its time.
     *
     * @throws ProcessTimedOutException
     */
    public static function check(Process $process, float $now): void
    {
        $id = spl_object_id($process);

        if (! array_key_exists($id, self::$watched)) {
            return;
        }

        $run = self::$watched[$id];
        $past = $run->pastLimit($process->getIncrementalErrorOutput(), $now);

        if (! $past instanceof NotGiven) {
            unset(self::$watched[$id]);
            self::recorded($past instanceof Overrun
                ? RecordLine::overran($run->mutated(), $past->test()->value(), $past->limit()->seconds())
                : RecordLine::silent($run->mutated(), $past->seconds()));
            $process->setTimeout(Progress::RUN_PAST);
            $process->checkTimeout();
        }
    }

    /** Writes how an own run was stopped, where the gate names a file. */
    private static function recorded(string $line): void
    {
        $results = getenv(GateVariable::Results->value);

        if (is_string($results) && $results !== '') {
            file_put_contents($results, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
