<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function file_put_contents;
use function getenv;

use Infection\Mutant\Mutant;

use function is_string;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function spl_object_id;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The silence limit of each mutant's run, kept in a patched Infection's
 * process (see Patch, ADR-0008 decision 2): a run that has printed nothing
 * for its limit since it last printed is stopped, as one at its whole limit
 * is, and the stop is recorded, by the mutant, in the file the gate names.
 * The test framework prints its header once it has loaded every test, and a
 * mark as each test ends, so its loading before is held only to the whole
 * limit. A run whose limit is none is never stopped for silence.
 */
final class Silence
{
    /** @var array<int, array{Progress, Mutant, Seconds}> each run's progress, mutant and limit, by its process */
    private static array $watched = [];

    /**
     * Watches a mutant's run, under Infection's `timeout`. Whatever was
     * watched under its process's id before is forgotten, since PHP gives a
     * freed process's id to the next.
     */
    public static function watch(Process $process, Mutant $mutant, float $timeout): void
    {
        $id = spl_object_id($process);
        $limit = MutantTime::silence($mutant->getTests(), $timeout);
        unset(self::$watched[$id]);

        if ($limit instanceof Seconds) {
            self::$watched[$id] = [Progress::under($limit), $mutant, $limit];
        }
    }

    /**
     * Stops a run that has printed nothing for its silence limit since it
     * last printed, as it stands at these seconds of the clock: its time is
     * cut to less than it has run, so Symfony's Process stops it and says it
     * ran out of its time.
     *
     * @throws ProcessTimedOutException
     */
    public static function check(Process $process, float $now): void
    {
        $id = spl_object_id($process);

        if (! array_key_exists($id, self::$watched)) {
            return;
        }

        [$progress, $mutant, $limit] = self::$watched[$id];
        $progress = $progress->read(mb_strlen($process->getOutput()), $now);
        self::$watched[$id] = [$progress, $mutant, $limit];

        if ($progress->hasStalled($now)) {
            unset(self::$watched[$id]);
            self::recorded($mutant, $limit);
            $process->setTimeout(Progress::RUN_PAST);
            $process->checkTimeout();
        }
    }

    /** Writes that the mutant's run was stopped at this silence limit, where the gate names a file. */
    private static function recorded(Mutant $mutant, Seconds $limit): void
    {
        $results = getenv(ChildVariable::Results->value);

        if (is_string($results) && $results !== '') {
            file_put_contents($results, Silenced::line($mutant, $limit), FILE_APPEND | LOCK_EX);
        }
    }
}
