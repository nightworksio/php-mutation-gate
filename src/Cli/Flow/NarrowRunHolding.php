<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;

/**
 * What a narrowed run holds to a floor: a run of the security mutators
 * alone judges none of the trees (ADR-0021, decision 20), and a run of one
 * suite's tests alone holds no tree, and no security set, to one (ADR-0025,
 * decision 9).
 */
final readonly class NarrowRunHolding
{
    /** Why each tree of a run of the security mutators alone is held to no floor. */
    private const string UNJUDGED_TREES = '--security judges only the security sets';

    /** Why each tree and security set of one suite's run is held to no floor. */
    private const string UNHELD = '--suite judges no floor';

    public function __construct(private Adapters $adapters)
    {
    }

    /**
     * The trees, each exempt in a run of the security mutators alone, which
     * judges none of them, and in a run of one suite's tests alone, which
     * holds no floor.
     */
    public function trees(Trees $trees): Trees
    {
        $why = match (true) {
            $this->adapters->isSecurityOnly() => Exempt::because(self::UNJUDGED_TREES),
            $this->adapters->isSuiteOnly() => Exempt::because(self::UNHELD),
            default => NotGiven::value(),
        };

        if (! $why instanceof Exempt) {
            return $trees;
        }

        $exempt = Trees::none();

        foreach ($trees as $tree) {
            $exempt = $exempt->with($tree->declaring($why));
        }

        return $exempt;
    }

    /**
     * Each package's security set, exempt in a run of one suite's tests alone
     * that does not make mutants with the security mutators alone, which
     * holds no floor (ADR-0025, decision 9).
     */
    public function security(SecurityVerdicts $security): SecurityVerdicts
    {
        if ($this->adapters->isSecurityOnly() || ! $this->adapters->isSuiteOnly()) {
            return $security;
        }

        $exempt = [];

        foreach ($security as $set) {
            $exempt[] = $set->exempting(Exempt::because(self::UNHELD));
        }

        return SecurityVerdicts::of(...$exempt);
    }
}
