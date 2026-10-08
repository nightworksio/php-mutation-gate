<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function getenv;

use Infection\AbstractTestFramework\Coverage\TestLocation;

use function is_float;
use function max;

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a patched Infection allows one mutant, read in Infection's
 * process (see Patch): the standard mutant limit (ADR-0008, decision 2) of
 * its covering tests' own time, as Infection timed them, laid on the
 * start-up the gate's run measured, between the floor
 * the gate names and Infection's `timeout`, which the gate sets to
 * `timeouts.most`. Where the gate names no floor, as when Infection runs
 * outside it, Infection's own limit.
 */
final class MutantTime
{
    /** The seconds a mutant whose covering tests take this long is allowed, under Infection's `timeout`. */
    public static function of(float $tests, float $timeout): float
    {
        $floor = self::floor();
        $taking = Seconds::of($tests);
        $most = Seconds::of($timeout);

        return $floor instanceof Seconds
            ? MutantLimit::standard()
                ->of($taking, LimitBounds::between($floor, $most)->startingIn(self::startUp()))
                ->seconds()
            : MutantLimit::infections()->of($taking, LimitBounds::upTo($most))->seconds();
    }

    /**
     * The silence limit of a mutant's run, its covering tests as Infection
     * located them, by its mutator's name: the standard limit of the slowest's
     * own time, between the floor the gate names, or the lower one
     * `timeouts.tighter` gives the mutator where it lists it, and Infection's
     * `timeout`. Infection times each test by its whole class, which a single
     * test never takes longer than.
     * None where the gate names no floor, or Infection timed not every one of
     * them, as a test of unknown length could be stopped while it runs.
     *
     * @param array<TestLocation> $tests
     */
    public static function silence(array $tests, float $timeout, string $mutator): Seconds|NotGiven
    {
        $floor = self::floor();
        $slowest = self::slowest($tests);
        $tighter = TighterVariables::read(
            getenv(ChildVariable::TighterFloor->value),
            getenv(ChildVariable::TighterMutators->value),
        );

        return $floor instanceof Seconds && $slowest instanceof Seconds
            ? MutantLimit::standard()->of(
                $slowest,
                LimitBounds::between($floor, Seconds::of($timeout))
                    ->startingIn(self::startUp())
                    ->tighterFor($tighter)
                    ->silenceOf(RunnerMutatorName::of($mutator)),
            )
            : NotGiven::value();
    }

    /** Whether the gate names its bounds, so no mutant is skipped for the time its tests take. */
    public static function bounded(): bool
    {
        return self::floor() instanceof Seconds;
    }

    /**
     * The time of the slowest of these tests, as Infection timed it; none where
     * one is untimed, or there are none.
     *
     * @param array<TestLocation> $tests
     */
    private static function slowest(array $tests): Seconds|NotGiven
    {
        $slowest = 0.0;

        foreach ($tests as $test) {
            $time = $test->getExecutionTime();

            if (! is_float($time)) {
                return NotGiven::value();
            }

            $slowest = max($slowest, $time);
        }

        return $tests === [] ? NotGiven::value() : Seconds::of($slowest);
    }

    /** The start-up the gate's run measured, or none. */
    private static function startUp(): Seconds|Unmeasured
    {
        return StartUpVariable::read(getenv(ChildVariable::MutantStartUp->value));
    }

    /** The floor the gate names, a positive number of seconds; or none. */
    private static function floor(): Seconds|NotGiven
    {
        return ToldSeconds::read(getenv(ChildVariable::MutantFloor->value));
    }
}
