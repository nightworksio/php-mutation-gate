<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A project of two trees whose files three CODEOWNERS rules own: `src/Billing`
 * by `@acme/payments @jo`, `src/Http` by `@acme/web`, and `lib` by no one.
 * A change touched the billing code's second line and the web code's first.
 */
final class Owners
{
    public const string FILE = "/src/Billing/ @acme/payments @jo\n/src/Http/ @acme/web\n";

    public static function codeOwners(): CodeOwners
    {
        return CodeOwners::read(Path::of('.github/CODEOWNERS'), self::FILE);
    }

    public static function trees(): TreeVerdicts
    {
        return TreeVerdicts::of(
            self::tree('src', JudgedMutants::of(
                self::changed(Judged::mutant('pay-killed', MutantJudgement::Killed, 'src/Billing/Pay.php', 1)),
                self::changed(Judged::mutant('pay-survived', MutantJudgement::Survived, 'src/Billing/Pay.php', 2)),
                Judged::mutant('pay-uncovered', MutantJudgement::Uncovered, 'src/Billing/Pay.php', 3),
                Judged::mutant('route-killed', MutantJudgement::Killed, 'src/Http/Route.php', 4),
            )->and(JudgedMutants::kills(Verdicts::provedKill()))),
            self::tree('lib', JudgedMutants::of(
                Judged::mutant('clock-survived', MutantJudgement::Survived, 'lib/Clock.php', 5),
            )),
        );
    }

    public static function verdicts(Uncovered $uncovered = Uncovered::Count): OwnerVerdicts
    {
        return OwnerVerdicts::of(self::trees(), self::codeOwners(), $uncovered);
    }

    /** The verdict over both trees, its mutants grouped by owner. */
    public static function verdict(): Verdict
    {
        return Verdict::of(self::trees())->withSets(HeldSets::none()->owned(self::verdicts()));
    }

    /** A mutant marked on a line the change added or modified. */
    public static function changed(JudgedMutant $mutant): JudgedMutant
    {
        $location = $mutant->mutant()->location();

        return $mutant->within(Reach::nothing(Packages::of(Trees::none()))->withLines(
            $location->file(),
            Lines::of(Line::of($location->start()->number())),
        ));
    }

    private static function tree(string $path, JudgedMutants $mutants): TreeVerdict
    {
        return TreeVerdict::judged(
            Tree::at(Path::of($path), Floor::of(50), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            $mutants,
            Uncovered::Count,
        );
    }
}
