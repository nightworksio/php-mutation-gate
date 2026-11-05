<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function explode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

use function sprintf;

/** Heatmaps over `src/Money.php`, whose functions are {@see MONEY}'s, and `src/Cart.php`, which declares none. */
final readonly class Heatmaps
{
    /** `Money::add` on lines 4 to 7, `Money::sub` on 9 to 13 with a closure on 11, and `helper` on 15 to 18. */
    public const string MONEY = <<<'PHP'
        <?php
        final class Money
        {
            public function add(int $a): int
            {
                return $a + 1;
            }

            public function sub(int $a): int
            {
                $f = function () { return 1; };
                return $a - 1;
            }
        }
        function helper(): int
        {
            return 2;
        }

        PHP;

    /**
     * A tree held to a floor, with these mutants, each `[file:line, judgement, on a changed line]`.
     *
     * @param list<array{string, MutantJudgement, bool}> $mutants
     */
    public static function tree(int $floor, array $mutants): TreeVerdicts
    {
        $judged = [];

        foreach ($mutants as $at => [$where, $judgement, $changed]) {
            [$file, $line] = explode(':', $where);
            $mutant = Verdicts::mutant($where, sprintf('M%d', $at), MutatorFamily::Literal, sprintf('-%d', $at));
            $one = JudgedMutant::of($mutant, $judgement);
            $judged[] = $changed
                ? $one->within(Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of($file), Lines::of(Line::of((int) $line))))
                : $one;
        }

        return TreeVerdicts::of(TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of($floor), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::of(JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Run)),
            JudgedMutants::of(...$judged),
            Uncovered::Count,
        ));
    }

    /** The functions of `src/Money.php`; none of any other file. */
    public static function spans(Path $file): FunctionSpans
    {
        return $file->value() === 'src/Money.php' ? FunctionSpans::in(Contents::of(self::MONEY)) : FunctionSpans::none();
    }

    /**
     * `src/Money.php` at 50% against 75% on the base, its `Money::sub` at 50%
     * against 100% and `Money::add` at 100%, a changed line in it; and
     * `src/Cart.php` at 50%, unchanged on the base and below the floor of 80,
     * a changed line in it too; listing 10 of the lowest and 5 that fell.
     */
    public static function fallen(): Heatmap
    {
        $now = self::tree(80, [
            ['src/Money.php:6', MutantJudgement::Killed, false],
            ['src/Money.php:12', MutantJudgement::Survived, true],
            ['src/Money.php:12', MutantJudgement::Killed, false],
            ['src/Money.php:2', MutantJudgement::Survived, false],
            ['src/Cart.php:3', MutantJudgement::Killed, true],
            ['src/Cart.php:4', MutantJudgement::Survived, false],
        ]);
        $base = self::tree(80, [
            ['src/Money.php:6', MutantJudgement::Killed, false],
            ['src/Money.php:12', MutantJudgement::Killed, false],
            ['src/Money.php:12', MutantJudgement::Killed, false],
            ['src/Money.php:2', MutantJudgement::Survived, false],
            ['src/Cart.php:3', MutantJudgement::Killed, false],
            ['src/Cart.php:4', MutantJudgement::Survived, false],
        ]);

        return Heatmap::of($now, self::spans(...))->comparedWith(Heatmap::of($base, self::spans(...)))->listing(10, 5);
    }
}
