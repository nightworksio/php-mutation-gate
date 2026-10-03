<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * What a stub is written for: a survivor or an uncovered mutant, or the first
 * survivor of a cluster with every member (ADR-0022, decision 16); the
 * function its code is in; and the tests that judge its unit.
 */
final readonly class Subject
{
    private function __construct(
        private JudgedMutant $first,
        private Survivors $members,
        private Cluster|Unclustered $cluster,
        private Enclosing|Nameless $function,
        private WholeSuite|Group|Filter $judgedBy,
    ) {
    }

    /** One mutant, in the function around it, its unit judged by these tests. */
    public static function of(
        JudgedMutant $mutant,
        Enclosing|Nameless $function,
        WholeSuite|Group|Filter $judgedBy,
    ): self {
        return new self($mutant, Survivors::of($mutant), Unclustered::mutant(), $function, $judgedBy);
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

    /** The tests that judge the unit: a group holds it, a filter, or every test that covers it. */
    public function judgedBy(): WholeSuite|Group|Filter
    {
        return $this->judgedBy;
    }
}
