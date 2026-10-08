<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function spl_object_id;
use function str_contains;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The silence limit of each mutant's own run, kept in Pest's parent
 * process (ADR-0008, decision 2): a run no test of which has finished for
 * its limit since its tests began is stopped, as one at its whole limit is,
 * and the stop is recorded by the mutant's mutated copy. Each own run tells
 * of its progress on its error output (see Recording\Heartbeat): once as
 * its tests begin, which its loading before is not held to, and as each
 * test finishes. A run whose limit is none is never stopped for silence.
 */
final class Silence
{
    /** @var array<int, true> */
    private static array $diagged = [];

    /** What an own run writes on its error output as its tests begin and as each finishes. */
    public const string BEAT = "\x06";


    /** @var array<int, array{float, string, float}> each run's limit, mutated copy and last beat, by its process */
    private static array $watched = [];

    /**
     * Watches an own run, its covering tests named by their ids as the
     * coverage map names them, by its mutated copy and its mutator's class,
     * where the run passes this filter of them. A run whose filter was left
     * out runs every test it loads, of which the slowest is not known, so it
     * is not watched. Whatever was watched under its process's id before is
     * forgotten, since PHP gives a freed process's id to the next.
     *
     * @param list<string> $tests
     */
    public static function watch(Process $process, array $tests, string $mutated, string $filter, string $mutator): void
    {
        $limit = MutantTime::silence($tests, $mutator);
        unset(self::$watched[spl_object_id($process)]);

        if ($limit instanceof Seconds && Ceiling::admits($filter)) {
            self::$watched[spl_object_id($process)] = [$limit->seconds(), $mutated, 0.0];
        }
    }

    /**
     * Stops a run no test of which has finished for its silence limit, as it
     * stands at these seconds of the clock: its time is cut to less than it
     * has run, so Symfony's Process stops it and says it ran out of its time.
     *
     * @throws ProcessTimedOutException
     */
    public static function check(Process $process, float $now): void
    {
        $id = spl_object_id($process);

        if (! array_key_exists($id, self::$watched)) {
            return;
        }

        [$limit, $mutated, $beat] = self::$watched[$id];
        $diag = getenv('DIAG_OWN_RUNS');
        $timeout = $process->getTimeout();
        try {
            $started = $process->getStartTime();
        } catch (\Throwable) {
            $started = $now;
        }
        if (is_string($diag) && $diag !== '' && $timeout !== null && $now - $started > $timeout - 0.3 && ! isset(self::$diagged[$id])) {
            self::$diagged[$id] = true;
            file_put_contents($diag, sprintf("=== %s TIMEOUT near %.1fs (beat %.1fs ago, silence %.1fs)\n%s\n%s\n", $mutated, $timeout, $beat > 0.0 ? $now - $beat : -1.0, $limit, $process->getOutput(), $process->getErrorOutput()), FILE_APPEND);
        }
        $beat = str_contains($process->getIncrementalErrorOutput(), self::BEAT) ? $now : $beat;
        self::$watched[$id] = [$limit, $mutated, $beat];

        if ($beat > 0.0 && $now - $beat > $limit) {
            unset(self::$watched[$id]);
            self::recorded($mutated, $limit);
            $process->setTimeout(Progress::RUN_PAST);
            $process->checkTimeout();
        }
    }

    /** Writes that the own run on this mutated copy was stopped at this silence limit, where the gate names a file. */
    private static function recorded(string $mutated, float $limit): void
    {
        $results = getenv(GateVariable::Results->value);

        if (is_string($results) && $results !== '') {
            file_put_contents($results, RecordLine::silent($mutated, $limit), FILE_APPEND | LOCK_EX);
        }
    }
}
