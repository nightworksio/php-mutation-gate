<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a run is narrowed to: the mutators it makes mutants with, every one
 * or those `--security` names (ADR-0021, decision 20), the one suite whose
 * tests alone judge them, as `--suite` names it (ADR-0025, decision 9), the
 * suites whose tests judge them otherwise, as `tests.suites` and
 * `tests.holding` list them (ADR-0002), and the mutators it leaves out of the files whose content is unchanged
 * (ADR-0025, decision 1).
 */
final readonly class Narrowing
{
    private function __construct(
        private Mutators $mutators,
        private SuiteName|NotGiven $suite,
        private JudgingSuites $suites,
        private Pruned $pruned,
    ) {
    }

    /** Every mutator, judged by every test, on every file. */
    public static function none(): self
    {
        return new self(Mutators::all(), NotGiven::value(), JudgingSuites::every(), Pruned::none());
    }

    /** This narrowing, making mutants with these mutators alone. */
    public function toMutators(Mutators $mutators): self
    {
        return clone($this, ['mutators' => $mutators]);
    }

    /** This narrowing, its mutants judged by this suite's tests alone. */
    public function toSuite(SuiteName $suite): self
    {
        return clone($this, ['suite' => $suite]);
    }

    /** This narrowing, its mutants judged by these suites' tests, as `tests.suites` and `tests.holding` list them. */
    public function amongSuites(JudgingSuites $suites): self
    {
        return clone($this, ['suites' => $suites]);
    }

    /** This narrowing, leaving these mutators out of these files. */
    public function pruning(Pruned $pruned): self
    {
        return clone($this, ['pruned' => $pruned]);
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
     * the config lists for them (ADR-0002, decision 8), every suite by default.
     */
    public function suitesFor(WholeSuite|Group|Filter|TestPaths $tests): Suites
    {
        return $this->suite instanceof SuiteName ? Suites::named($this->suite) : $this->suites->for($tests);
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
