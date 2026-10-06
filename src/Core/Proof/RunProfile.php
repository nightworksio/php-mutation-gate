<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

/**
 * What a run's results are of, beyond its units: how much of the kill matrix
 * it records (ADR-0014, decision 7), whether it makes mutants with the
 * security mutators alone (ADR-0021, decision 20), and the one suite whose
 * tests alone judge them, where there is one (ADR-0025, decision 9). A run
 * reads its change since its scope's last run only where that run was of
 * the same kind (ADR-0005, decision 2).
 */
final readonly class RunProfile
{
    private function __construct(
        private MatrixKind $matrix,
        private bool $securityOnly,
        private SuiteName|NotGiven $suite,
    ) {
    }

    /** First killers recorded, and mutants made with every mutator the run turns on, judged by every test. */
    public static function standard(): self
    {
        return new self(MatrixKind::FirstKiller, securityOnly: false, suite: NotGiven::value());
    }

    /** This kind, recording this much of the kill matrix. */
    public function recording(MatrixKind $matrix): self
    {
        return clone($this, ['matrix' => $matrix]);
    }

    /** This kind, for a run that makes mutants with the security mutators alone, as `--security` asks. */
    public function securityOnly(): self
    {
        return clone($this, ['securityOnly' => true]);
    }

    /** This kind, for a run whose mutants one suite's tests alone judge, as `--suite` asks. */
    public function inSuite(SuiteName $suite): self
    {
        return clone($this, ['suite' => $suite]);
    }

    /** How much of the kill matrix the run records. */
    public function matrix(): MatrixKind
    {
        return $this->matrix;
    }

    /** Whether the run makes mutants with the security mutators alone, and holds only the security sets. */
    public function isSecurityOnly(): bool
    {
        return $this->securityOnly;
    }

    /** The suite whose tests alone judge the run's mutants; none where every test judges them. */
    public function suite(): SuiteName|NotGiven
    {
        return $this->suite;
    }

    /** Whether another kind is this one: the same matrix, the same mutators, and the same suite. */
    public function equals(self $other): bool
    {
        return $this->matrix === $other->matrix
            && $this->securityOnly === $other->securityOnly
            && $this->suiteNamed($this->suite) === $this->suiteNamed($other->suite);
    }

    /** A suite's name, or the empty name no suite has where every test judges. */
    private function suiteNamed(SuiteName|NotGiven $suite): string
    {
        return $suite instanceof SuiteName ? $suite->value() : '';
    }
}
