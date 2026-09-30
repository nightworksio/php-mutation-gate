<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

use function sprintf;

/**
 * `src/Cart.php`'s `return false;` on line 11, survived, and the two Pest
 * tests that cover it: `it fits`, which asserts only the result's type, and
 * `it refuses`, which asserts its value. A test the runner names no file for
 * covers line 7.
 */
final class Weakly
{
    public const string TESTS = 'tests/CartTest.php';

    public const string TEST_FILE = <<<'PHP'
        <?php

        it('fits', function () {
            expect((new Cart())->fits(1, 2))->toBeBool()->not->toBeNull();
        });

        it('refuses', function () {
            expect((new Cart())->fits(3, 2))->toBe(false);
        });

        PHP;

    /** What a PHPUnit test is told to assert on a result outside any function. */
    public const string PHPUNIT_OUTSIDE = '$this->assertSame(<expected>, …)';

    public static function weakTest(): TestId
    {
        return TestId::of('P\Tests\CartTest::__pest_evaluable_it_fits');
    }

    public static function strongTest(): TestId
    {
        return TestId::of('P\Tests\CartTest::__pest_evaluable_it_refuses');
    }

    /** A test the runner names no file for. */
    public static function unnamedTest(): TestId
    {
        return TestId::of('Tests\OrderTest::testTotals');
    }

    /** The survivor of line 11, a Literal. */
    public static function literal(): JudgedMutant
    {
        return self::survivor(11, 'FalseValue', Family::Literal, Verdicts::diff('return false;', 'return true;'));
    }

    /** A survivor of line 7, a Boundary, which an assertion of value may not see. */
    public static function boundary(): JudgedMutant
    {
        return self::survivor(7, 'LessThan', Family::Boundary, Verdicts::diff('if ($amount < $limit) {', 'if ($amount <= $limit) {'));
    }

    public static function survivor(int $line, string $mutator, Family $family, string $diff, Judged $judgement = Judged::Survived): JudgedMutant
    {
        return JudgedMutant::of(Verdicts::mutant(sprintf('src/Cart.php:%d', $line), $mutator, $family, $diff), $judgement);
    }

    /** The coverage of the Cart: line 11 by both Pest tests, line 7 by the unnamed test and the weak one, named. */
    public static function matrix(TestId ...$onEleven): KillMatrix
    {
        $coverage = CoverageMap::empty()
            ->covered(Path::of(Clustered::FILE), Line::of(7), self::unnamedTest())
            ->covered(Path::of(Clustered::FILE), Line::of(7), self::weakTest());

        foreach ($onEleven === [] ? [self::weakTest(), self::strongTest()] : $onEleven as $test) {
            $coverage = $coverage->covered(Path::of(Clustered::FILE), Line::of(11), $test);
        }

        return KillMatrix::of(MatrixKind::FirstKiller, $coverage)->named(TestNames::none()
            ->with(self::weakTest(), TestName::in(Path::of(self::TESTS), 'it fits'))
            ->with(self::strongTest(), TestName::in(Path::of(self::TESTS), 'it refuses')));
    }

    public static function trees(JudgedMutant ...$mutants): TreeVerdicts
    {
        return TreeVerdicts::of(TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(...$mutants),
            Uncovered::Count,
        ));
    }

    /** The Cart's source, by its path. */
    public static function sources(): ByPath
    {
        return Clustered::sources();
    }

    /** The Cart's test file, by its path. */
    public static function tests(): ByPath
    {
        return ByPath::none()->with(Path::of(self::TESTS), Contents::of(self::TEST_FILE));
    }
}
