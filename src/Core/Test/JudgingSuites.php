<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Which `<testsuite>`s' tests judge a run's mutants (ADR-0002, decision 8):
 * the suites whose tests judge every unit, as `tests.suites` lists them, and
 * the suites whose tests judge only the units they hold, as `tests.holding`
 * lists them. A run of the whole suite, or of some test files, runs the
 * first alone; a run of the tests that hold a unit runs both, and so does a
 * run of mutants whose tests a map the gate wrote picks (see Narrowing).
 */
final readonly class JudgingSuites
{
    private function __construct(private Suites $judging, private Suites|NotGiven $holding)
    {
    }

    /** Every suite judges every unit, as with neither key written. */
    public static function every(): self
    {
        return new self(Suites::all(), NotGiven::value());
    }

    /** These suites judge every unit, and none judges only the units it holds. */
    public static function judging(Suites $judging): self
    {
        return new self($judging, NotGiven::value());
    }

    /** These suites judge every unit, and those judge only the units they hold. */
    public static function holding(Suites $judging, Suites $holding): self
    {
        return new self($judging, $holding);
    }

    /** The suites whose tests judge every unit: every suite where none is named. */
    public function everyUnit(): Suites
    {
        return $this->judging;
    }

    /** The suites whose tests judge only the units they hold, where any is named. */
    public function heldUnits(): Suites|NotGiven
    {
        return $this->holding;
    }

    /** Whether either list names a suite, so that the PHPUnit config must declare it. */
    public function namesAny(): bool
    {
        return ! $this->judging->isAll() || $this->holding instanceof Suites;
    }

    /** The suites a run of these tests runs: the holding suites too where the tests hold a unit. */
    public function for(WholeSuite|Group|Filter|TestPaths $tests): Suites
    {
        return ($tests instanceof Group || $tests instanceof Filter) && $this->holding instanceof Suites
            ? $this->judging->and($this->holding)
            : $this->judging;
    }
}
