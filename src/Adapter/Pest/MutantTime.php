<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_unique;
use function file_put_contents;
use function getenv;
use function is_numeric;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a patched Pest allows one mutant, read in Pest's parent
 * process: the standard mutant limit (ADR-0008, decision 2) of its covering
 * tests' own time, as the coverage map Pest loaded timed them, within the
 * bounds the gate names. A covering test the map did not time leaves the
 * floor.
 *
 * Each limit binds only once it is recorded, by the mutant's mutated copy,
 * in the results file the gate names, so the gate triages each timeout by
 * the limit that applied; where it cannot be recorded, or the gate names no
 * bounds, the mutant keeps the limit Pest gave it.
 */
final class MutantTime
{
    /** @var array<string, float> each test's seconds, by its id, as the map Pest loaded timed it */
    private static array $timed = [];

    /**
     * Keeps each test's seconds from the coverage map Pest loaded, as
     * php-code-coverage writes one since version 14, the one the gate reads.
     * A test timed at no seconds is not timed.
     *
     * @param array{testResults?: array<string, array{time: float}>} $loaded
     */
    public static function remember(array $loaded): void
    {
        $results = array_key_exists('testResults', $loaded) ? $loaded['testResults'] : [];
        self::$timed = [];

        foreach ($results as $test => $result) {
            self::$timed += $result['time'] > 0.0 ? [$test => $result['time']] : [];
        }
    }

    /**
     * The seconds the mutant with this mutated copy is allowed, its covering
     * tests named by their ids as Pest's coverage map names them; the limit
     * Pest gave it where the gate names no bounds or the limit cannot be
     * recorded.
     *
     * @param list<string> $tests
     */
    public static function of(array $tests, string $mutated, int $pest): float
    {
        $bounds = self::bounds();
        $results = getenv(GateVariable::Results->value);

        if (! $bounds instanceof LimitBounds) {
            return $pest;
        }

        $limit = MutantLimit::standard()->of(self::ownTime($tests), $bounds)->seconds();

        $recorded = $mutated !== '' && is_string($results) && $results !== ''
            && file_put_contents($results, RecordLine::limited($mutated, $limit), FILE_APPEND | LOCK_EX) !== false;

        return $recorded ? $limit : $pest;
    }

    /**
     * The silence limit of a mutant's own run, its covering tests named by
     * their ids, by its mutator's class: the standard limit of the slowest
     * one's own time, within the bounds the gate names, with the lower floor
     * `timeouts.tighter` gives the mutator where it lists it (ADR-0008,
     * decision 2); none where the gate names no bounds or the map timed not
     * every one of them, as a test of unknown length could be stopped while
     * it runs.
     *
     * @param list<string> $tests
     */
    public static function silence(array $tests, string $mutator): Seconds|NotGiven
    {
        $bounds = self::bounds();
        $slowest = self::slowest($tests);

        return $bounds instanceof LimitBounds && $slowest instanceof Seconds
            ? MutantLimit::standard()->of($slowest, $bounds->silenceOf(RunnerMutatorName::of($mutator)))
            : NotGiven::value();
    }

    /**
     * The bounds the gate names, the floor and the most, each a positive
     * number of seconds, with the mutators `timeouts.tighter` lists; or none.
     */
    private static function bounds(): LimitBounds|NotGiven
    {
        $floor = getenv(GateVariable::MutantFloor->value);
        $most = getenv(GateVariable::MutantCap->value);
        $named = is_string($floor) && is_numeric($floor) && (float) $floor > 0.0
            && is_string($most) && is_numeric($most) && (float) $most > 0.0;

        return $named
            ? LimitBounds::between(Seconds::of((float) $floor), Seconds::of((float) $most))->tighterFor(
                TighterVariables::read(
                    getenv(GateVariable::TighterFloor->value),
                    getenv(GateVariable::TighterMutators->value),
                ),
            )
            : NotGiven::value();
    }

    /** @param list<string> $tests */
    private static function ownTime(array $tests): Seconds|Unmeasured
    {
        $total = 0.0;

        foreach (array_unique($tests) as $test) {
            if (! array_key_exists($test, self::$timed)) {
                return Unmeasured::duration();
            }

            $total += self::$timed[$test];
        }

        return $tests === [] ? Unmeasured::duration() : Seconds::of($total);
    }

    /**
     * The own time of the slowest of these tests; unmeasured where the map
     * timed not every one of them, or there are none.
     *
     * @param list<string> $tests
     */
    private static function slowest(array $tests): Seconds|Unmeasured
    {
        $slowest = 0.0;

        foreach ($tests as $test) {
            if (! array_key_exists($test, self::$timed)) {
                return Unmeasured::duration();
            }

            $slowest = max($slowest, self::$timed[$test]);
        }

        return $tests === [] ? Unmeasured::duration() : Seconds::of($slowest);
    }
}
