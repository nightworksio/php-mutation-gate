<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * `src/Cart.php` and its survivors: three that change the comparison on its
 * seventh line, three removed calls in `save()` that the same tests judge,
 * and one lone survivor on its eleventh line. Its seventh and sixteenth to
 * eighteenth lines are changed.
 */
final class Clustered
{
    public const string FILE = 'src/Cart.php';

    public const string CART = <<<'PHP'
        <?php

        final class Cart
        {
            public function fits(int $amount, int $limit): bool
            {
                if ($amount < $limit) {
                    return true;
                }

                return false;
            }

            public function save(Order $order): void
            {
                $this->log->write($order);
                $this->events->dispatch($order);
                $this->cache->forget($order->id);
            }
        }

        PHP;

    /** The comparison of line 7, as the boundary, flipped and negated. */
    public static function comparisons(): JudgedMutants
    {
        return JudgedMutants::of(
            self::survivor(7, 'LessThan', Family::Boundary, 'if ($amount <= $limit) {'),
            self::survivor(7, 'LessThanNegotiation', Family::Boundary, 'if ($amount > $limit) {'),
            self::survivor(7, 'IfNegation', Family::Condition, 'if (! ($amount < $limit)) {'),
        );
    }

    /** The three calls of `save()`, each removed. */
    public static function removedCalls(): JudgedMutants
    {
        return JudgedMutants::of(
            self::removed(16, '$this->log->write($order);'),
            self::removed(17, '$this->events->dispatch($order);'),
            self::removed(18, '$this->cache->forget($order->id);'),
        );
    }

    /** The survivor of line 11, which shares its cause with none. */
    public static function lone(): JudgedMutant
    {
        return self::judged(
            Verdicts::mutant('src/Cart.php:11', 'FalseValue', Family::Literal, Verdicts::diff('return false;', 'return true;')),
            Judged::Survived,
        );
    }

    /** Every survivor, in the order a runner reports them: by line. */
    public static function survivors(): JudgedMutants
    {
        return self::comparisons()->and(JudgedMutants::of(self::lone()))->and(self::removedCalls());
    }

    /** The source of `src/Cart.php`, by its path. */
    public static function sources(): ByPath
    {
        return ByPath::none()->with(Path::of(self::FILE), Contents::of(self::CART));
    }

    /** A verdict over every survivor, clustered, in one tree held to 80%. */
    public static function verdict(): Verdict
    {
        return Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            self::survivors()->clustered(Uncovered::Count, self::sources()),
            Uncovered::Count,
        )));
    }

    /**
     * The mutants a run reported in full among these, as a list: a kill a
     * ledger proved is in no cluster.
     *
     * @return list<JudgedMutant>
     */
    public static function listed(JudgedMutants $mutants): array
    {
        $listed = [];

        foreach ($mutants as $judged) {
            if ($judged instanceof JudgedMutant) {
                $listed[] = $judged;
            }
        }

        return $listed;
    }

    /** The tests that judge every survivor of `src/Cart.php`. */
    public static function tests(): TestIds
    {
        return TestIds::of(TestId::of('CartTest::fits'), TestId::of('CartTest::saves'));
    }

    private static function survivor(int $line, string $mutator, Family $family, string $added): JudgedMutant
    {
        return self::judged(
            Verdicts::mutant(sprintf('src/Cart.php:%d', $line), $mutator, $family, Verdicts::diff('if ($amount < $limit) {', $added)),
            Judged::Survived,
        );
    }

    private static function removed(int $line, string $call): JudgedMutant
    {
        return self::judged(
            Verdicts::mutant(sprintf('src/Cart.php:%d', $line), 'MethodCallRemoval', Family::RemovedCall, Verdicts::diff($call, '')),
            Judged::Survived,
        );
    }

    private static function judged(Mutant $mutant, Judged $judgement): JudgedMutant
    {
        return JudgedMutant::of($mutant, $judgement)
            ->judgedBy(self::tests())
            ->within(Reach::nothing(Packages::of(Trees::none()))->withLines(
                Path::of(self::FILE),
                Lines::of(Line::of(7), Line::of(16), Line::of(17), Line::of(18)),
            ));
    }
}
