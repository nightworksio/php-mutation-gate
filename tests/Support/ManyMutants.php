<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function range;
use function sprintf;

/**
 * A verdict of as many mutants as a test asks for, all of `src/Money.php`,
 * one to a line, for the tests that keep reporting linear in a verdict's
 * size: each line is covered by `A` and `B` of the unit suite and `C` and
 * `D` of the feature suite ({@see Killings::suites()}) under a full matrix,
 * and every fourth mutant survives while `A` kills the rest. A widely
 * covered verdict has every line covered by as many more tests as a test
 * asks for besides.
 */
final class ManyMutants
{
    private const array TESTS = ['A', 'B', 'C', 'D'];

    public static function verdict(int $count): Verdict
    {
        return self::covered($count, self::TESTS);
    }

    /** A verdict of this many mutants, each line covered by this many tests besides `A` to `D`, each named. */
    public static function widely(int $count, int $tests): Verdict
    {
        return self::covered(
            $count,
            [...self::TESTS, ...array_map(static fn(int $test): string => sprintf('Widely%d', $test), range(1, $tests))],
        );
    }

    /** @param list<string> $tests */
    private static function covered(int $count, array $tests): Verdict
    {
        $money = Path::of('src/Money.php');
        $ids = array_map(static fn(string $test): string => Killings::id($test)->value(), $tests);
        $names = TestNames::none();

        foreach ($tests as $test) {
            $names = $names->with(Killings::id($test), Killings::name($test));
        }

        $coverage = CoverageMap::of(...array_map(
            static fn(int $line): CoveredLine => CoveredLine::of($money, $line, ...$ids),
            range(1, $count),
        ));
        $tree = TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(...array_map(self::mutant(...), range(1, $count))),
            Uncovered::Count,
        );

        return Verdict::of(TreeVerdicts::of($tree))->withMatrix(
            KillMatrix::of(MatrixKind::Full, $coverage)->named($names)->grouping(Killings::suites()),
        );
    }

    private static function mutant(int $line): JudgedMutant
    {
        $money = Path::of('src/Money.php');
        $diff = sprintf("-a%d\n+b%d\n", $line, $line);
        $status = $line % 4 === 0 ? MutantStatus::Survived : MutantStatus::Killed;
        $mutant = Mutant::of(
            MutantId::hash($money, 'Plus', $diff, 0),
            sprintf('native-%d', $line),
            Location::of($money, Line::of($line), Line::of($line)),
            Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
            $status,
            Unmeasured::duration(),
        )->killedBy($status === MutantStatus::Killed ? TestIds::of(Killings::id('A')) : TestIds::none());

        return JudgedMutant::of($mutant, MutantJudgement::reported($status));
    }
}
