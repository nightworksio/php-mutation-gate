<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestCases;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a run is narrowed to: the mutators it makes mutants with, every one
 * or those `--security` names (ADR-0021), the one suite whose
 * tests alone judge them, as `--suite` names it (ADR-0025), the
 * suites whose tests judge them otherwise, as `tests.suites` and
 * `tests.holding` list them (ADR-0002), the mutators it leaves out of the files whose content is unchanged
 * (ADR-0025), the files the suite runs as files of test cases, as its config declares them, and the part
 * of the test files whose tests judge them, where a run cuts its test files across its shards (ADR-0117).
 */
final readonly class Narrowing
{
    private function __construct(
        private Mutators $mutators,
        private SuiteName|NotGiven $suite,
        private JudgingSuites $suites,
        private Pruned $pruned,
        private TestCases $cases,
        private TestPart $part,
        private LineRecording $recording,
    ) {
    }

    /** Every mutator, judged by every test, on every file. */
    public static function none(): self
    {
        return new self(
            Mutators::all(),
            NotGiven::value(),
            JudgingSuites::every(),
            Pruned::none(),
            TestCases::unknown(),
            TestPart::whole(),
            LineRecording::none(),
        );
    }

    /** This narrowing, making mutants with these mutators alone. */
    public function toMutators(Mutators $mutators): self
    {
        return new self(
            $mutators,
            $this->suite,
            $this->suites,
            $this->pruned,
            $this->cases,
            $this->part,
            $this->recording,
        );
    }

    /** This narrowing, its mutants judged by this suite's tests alone. */
    public function toSuite(SuiteName $suite): self
    {
        return new self(
            $this->mutators,
            $suite,
            $this->suites,
            $this->pruned,
            $this->cases,
            $this->part,
            $this->recording,
        );
    }

    /** This narrowing, its mutants judged by these suites' tests, as `tests.suites` and `tests.holding` list them. */
    public function amongSuites(JudgingSuites $suites): self
    {
        return new self(
            $this->mutators,
            $this->suite,
            $suites,
            $this->pruned,
            $this->cases,
            $this->part,
            $this->recording,
        );
    }

    /** This narrowing, its runner told which files the suite runs as files of test cases. */
    public function amongTestCases(TestCases $cases): self
    {
        return new self(
            $this->mutators,
            $this->suite,
            $this->suites,
            $this->pruned,
            $cases,
            $this->part,
            $this->recording,
        );
    }

    /** Which files the suite runs as files of test cases; none known, unless it was told. */
    public function testCases(): TestCases
    {
        return $this->cases;
    }

    /** This narrowing, leaving these mutators out of these files. */
    public function pruning(Pruned $pruned): self
    {
        return new self(
            $this->mutators,
            $this->suite,
            $this->suites,
            $pruned,
            $this->cases,
            $this->part,
            $this->recording,
        );
    }

    /** This narrowing, its mutants judged by the tests of this part of the test files alone (ADR-0117). */
    public function inTestPart(TestPart $part): self
    {
        return new self(
            $this->mutators,
            $this->suite,
            $this->suites,
            $this->pruned,
            $this->cases,
            $part,
            $this->recording,
        );
    }

    /**
     * This narrowing, its split streams recording the lines each test
     * executes as this says, for a run planned without a fresh map
     * (ADR-0135) or a quick round's kept map (ADR-0147).
     */
    public function recordingInto(LineRecording $recording): self
    {
        return new self(
            $this->mutators,
            $this->suite,
            $this->suites,
            $this->pruned,
            $this->cases,
            $this->part,
            $recording,
        );
    }

    /** Where its split streams record each test's lines, and what for. */
    public function recording(): LineRecording
    {
        return $this->recording;
    }

    /** The part of the test files whose tests judge its mutants: every test file, unless it was told one. */
    public function testPart(): TestPart
    {
        return $this->part;
    }

    public function mutators(): Mutators
    {
        return $this->mutators;
    }

    public function suite(): SuiteName|NotGiven
    {
        return $this->suite;
    }

    /**
     * The suites a run of these tests runs: the one `--suite` names, or those
     * the config lists for them (ADR-0002), every suite by default.
     */
    public function suitesFor(WholeSuite|Group|Filter|TestPaths $tests): Suites
    {
        return $this->suite instanceof SuiteName ? Suites::named($this->suite) : $this->suites->for($tests);
    }

    /**
     * The suites whose tests judge only the units they hold, where the config
     * lists any and `--suite` names no one suite over both lists.
     */
    public function holdingSuites(): Suites|NotGiven
    {
        return $this->suite instanceof SuiteName ? NotGiven::value() : $this->suites->heldUnits();
    }

    /**
     * The suites a run of these tests runs where a coverage map the gate
     * wrote picks each mutant's tests: the holding suites too, whose tests
     * the map keeps only on the lines of the paths they hold
     * (ADR-0005).
     */
    public function mappedSuitesFor(WholeSuite|Group|Filter|TestPaths $tests): Suites
    {
        $holding = $this->holdingSuites();

        return $holding instanceof Suites ? $this->suitesFor($tests)->and($holding) : $this->suitesFor($tests);
    }

    /**
     * The suites the runs of mutants judged by these tests run: the holding
     * suites too where the whole suite judges through the maps a plan handed
     * over, which keep a holding suite's tests on the lines of what they hold.
     */
    public function suitesForMutants(WholeSuite|Group|Filter $tests, Handed|Fresh $coverage): Suites
    {
        return $coverage instanceof Handed && $tests instanceof WholeSuite
            ? $this->mappedSuitesFor($tests)
            : $this->suitesFor($tests);
    }

    /** The mutators it leaves out of the files whose content is unchanged. */
    public function pruned(): Pruned
    {
        return $this->pruned;
    }

    /** Whether it narrows nothing the CLI asks for: every mutator, and every test. */
    public function isNone(): bool
    {
        return $this->mutators->isAll() && ! $this->suite instanceof SuiteName;
    }
}
