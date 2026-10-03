<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * What a stub is written for: a survivor or an uncovered mutant, or the first
 * survivor of a cluster with every member (ADR-0022, decision 16); the
 * function its code is in; and its unit, which says what judges it.
 */
final readonly class Subject
{
    private function __construct(
        private JudgedMutant $first,
        private Survivors $members,
        private Cluster|Unclustered $cluster,
        private Enclosing|Nameless $function,
        private Unit $unit,
    ) {
    }

    /** One mutant, in the function around it, in its unit. */
    public static function of(JudgedMutant $mutant, Enclosing|Nameless $function, Unit $unit): self
    {
        return new self($mutant, Survivors::of($mutant), Unclustered::mutant(), $function, $unit);
    }

    /** This subject, standing for the whole of a cluster whose first survivor it is. */
    public function forCluster(Cluster $cluster): self
    {
        return clone($this, ['members' => $cluster->members(), 'cluster' => $cluster]);
    }

    /** The mutant the test is for: the one asked for, or a cluster's first survivor. */
    public function first(): JudgedMutant
    {
        return $this->first;
    }

    /** The mutant alone, or every member of its cluster, by line then id. */
    public function members(): Survivors
    {
        return $this->members;
    }

    public function cluster(): Cluster|Unclustered
    {
        return $this->cluster;
    }

    /** The function the first mutant's code is in; nothing for code in none. */
    public function function(): Enclosing|Nameless
    {
        return $this->function;
    }

    /** The unit the first mutant is in: one the whole suite judges, or one a group of tests holds. */
    public function unit(): Unit
    {
        return $this->unit;
    }
}
