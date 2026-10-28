<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_unique;
use function array_values;
use function file_put_contents;
use function getenv;
use function is_float;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * The seconds a patched Pest allows one mutant, read in Pest's parent
 * process: the standard mutant limit (ADR-0008) of its covering
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
    /**
     * The seconds the mutant with this mutated copy is allowed, its covering
     * tests named by their ids as Pest's coverage map names them, where its
     * run passes this filter of them: the standard limit of their own time.
     * A run whose filter was left out runs every test it loads, so it is
     * allowed the standard limit of the own time of each of those the map
     * timed, and it is recorded so (see CoveringFiles). The limit Pest gave
     * it where the gate names no bounds or the limit cannot be recorded.
     *
     * @param list<string> $tests
     */
    public static function of(array $tests, string $mutated, int $pest, string $filter): float
    {
        $bounds = self::bounds();
        $results = getenv(GateVariable::Results->value);

        if (! $bounds instanceof LimitBounds) {
            return $pest;
        }

        $passed = Selection::passable($filter);
        $running = $passed ? $tests : self::timed(CoveringFiles::loading($mutated, LoadedMap::tests()));
        $limit = MutantLimit::standard()->of(self::ownTime($running), $bounds)->seconds();
        $unfiltered = $passed ? '' : RecordLine::unfiltered($mutated);
        $lines = sprintf('%s%s', RecordLine::limited($mutated, $limit), $unfiltered);

        $recorded = $mutated !== '' && is_string($results) && $results !== ''
            && file_put_contents($results, $lines, FILE_APPEND | LOCK_EX) !== false;

        return $recorded ? $limit : $pest;
    }

    /**
     * The silence limit of a mutant's own run, its covering tests named by
     * their ids, by its mutator's class: the standard limit of the slowest
     * one's own time, within the bounds the gate names, with the lower floor
     * `timeouts.tighter` gives the mutator where it lists it (ADR-0008);
     * none where the gate names no bounds or the map timed not every one of
     * them, as a test of unknown length could be stopped while it runs.
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
     * number of seconds, with the mutators `timeouts.tighter` lists and the
     * start-up the run measured; or none.
     */
    private static function bounds(): LimitBounds|NotGiven
    {
        $floor = ToldSeconds::read(getenv(GateVariable::MutantFloor->value));
        $most = ToldSeconds::read(getenv(GateVariable::MutantCap->value));

        return $floor instanceof Seconds && $most instanceof Seconds
            ? LimitBounds::between($floor, $most)->tighterFor(
                TighterVariables::read(
                    getenv(GateVariable::TighterFloor->value),
                    getenv(GateVariable::TighterMutators->value),
                ),
            )->startingIn(StartUpVariable::read(getenv(GateVariable::MutantStartUp->value)))
            : NotGiven::value();
    }

    /** @param list<string> $tests */
    private static function ownTime(array $tests): Seconds|Unmeasured
    {
        $total = 0.0;

        foreach (array_unique($tests) as $test) {
            $seconds = LoadedMap::secondsOf($test);

            if (! is_float($seconds)) {
                return Unmeasured::duration();
            }

            $total += $seconds;
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
        $times = [];

        foreach ($tests as $test) {
            $seconds = LoadedMap::secondsOf($test);

            if (! is_float($seconds)) {
                return Unmeasured::duration();
            }

            $times[] = $seconds;
        }

        return $times === [] ? Unmeasured::duration() : Seconds::of(max($times));
    }

    /**
     * Those of these tests the map timed.
     *
     * @param  list<string> $tests
     * @return list<string>
     */
    private static function timed(array $tests): array
    {
        return array_values(array_filter(
            $tests,
            static fn(string $test): bool => is_float(LoadedMap::secondsOf($test)),
        ));
    }
}
