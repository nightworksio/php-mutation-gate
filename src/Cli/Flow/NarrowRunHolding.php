<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;

/**
 * What a narrowed run holds to a floor: a run of the security mutators
 * alone judges none of the trees (ADR-0021), a run of one
 * suite's tests alone holds no tree, and no security set, to one
 * (ADR-0025), and a sampled run, which estimates its trees, judges no
 * security set, since the units it left out hold security mutants it did
 * not run (ADR-0020).
 *
 * @internal
 */
final readonly class NarrowRunHolding
{
    /** Why each tree of a run of the security mutators alone is held to no floor. */
    private const string UNJUDGED_TREES = '--security judges only the security sets';

    /** Why each tree and security set of one suite's run is held to no floor. */
    private const string UNHELD = '--suite judges no floor';

    /** Why each security set of a sampled run is held to no floor. */
    private const string SAMPLED = '--sample estimates the trees and judges no security set';

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
     * holds no floor (ADR-0025), and in a sampled run.
     */
    public function security(SecurityVerdicts $security, Sample $sample): SecurityVerdicts
    {
        $why = match (true) {
            $sample->isDrawn() => Exempt::because(self::SAMPLED),
            $this->adapters->isSuiteOnly() && ! $this->adapters->isSecurityOnly() => Exempt::because(self::UNHELD),
            default => NotGiven::value(),
        };

        if (! $why instanceof Exempt) {
            return $security;
        }

        $exempt = [];

        foreach ($security as $set) {
            $exempt[] = $set->exempting($why);
        }

        return SecurityVerdicts::of(...$exempt);
    }
}
