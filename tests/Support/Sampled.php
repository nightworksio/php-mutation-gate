<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Spread;
use NightWorksIO\MutationGate\Core\Sample\Stratum;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A sampled run's trees, as a test needs them: a tree estimated at 75.55%,
 * between 60.70% and 85.26% at 95% confidence, with 3 of its 15 units
 * sampled and 5 known exactly.
 */
final class Sampled
{
    public static function sample(): Sample
    {
        return Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0);
    }

    public static function estimate(): Estimate
    {
        return Estimate::of(
            Tally::of(40, 50),
            5,
            Stratum::of(10, 100, Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10))),
            self::sample(),
        );
    }

    /** A tree estimated at 75.55%, held to this floor, with one killed and one surviving mutant it judged. */
    public static function tree(Floor|Exempt|Undeclared $floor, string $path = 'src'): TreeVerdict
    {
        return TreeVerdict::judged(
            Tree::at(Path::of($path), $floor, Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived),
            Uncovered::Count,
            self::estimate(),
        );
    }

    /** A verdict over one sampled tree held to this floor. */
    public static function verdict(Floor|Exempt|Undeclared $floor): Verdict
    {
        return Verdict::of(TreeVerdicts::of(self::tree($floor)));
    }
}
