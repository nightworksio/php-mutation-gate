<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
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
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * A verdict whose five tests the `tests` report judges, over five mutants
 * of `src/Money.php`, one to a line:
 *
 * - line 1, killed by `A` first, also covered by `B`'s first row;
 * - line 2, killed by `A`, and by `B`'s first row too under a full matrix;
 * - line 3, survived, covered by `B`'s second row and `C`;
 * - line 4, killed by `D`, also covered by `C`;
 * - line 5, timed out unless a test asks otherwise, covered by `E` alone.
 *
 * `A` runs for 0.4 seconds, `B`'s rows for 0.5 each, `C` for 0.2 and `E`
 * for 0.1; no coverage run timed `D`.
 */
final class Killings
{
    /** The verdict, its fifth mutant, the one `E` covers, reported with this status. */
    public static function verdict(MatrixKind $kind, MutantStatus $fifth = MutantStatus::TimedOut): Verdict
    {
        $full = $kind === MatrixKind::Full;
        $mutants = JudgedMutants::of(
            self::mutant(1, MutantStatus::Killed, self::ids('A')),
            self::mutant(2, MutantStatus::Killed, $full ? self::ids('A', 'B0') : self::ids('A')),
            self::mutant(3, MutantStatus::Survived, TestIds::none()),
            self::mutant(4, MutantStatus::Killed, self::ids('D')),
            self::mutant(5, $fifth, TestIds::none()),
        );
        $tree = TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::of(JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Run)),
            $mutants,
            Uncovered::Count,
        );

        return Verdict::of(TreeVerdicts::of($tree))->withMatrix(self::matrix($kind));
    }

    /**
     * A verdict of one held unit, `src/Log.php`, whose group holds `A`: its
     * one mutant, a survivor on line 1, is covered by `A` and `C`, and
     * judged by `A` alone.
     */
    public static function heldVerdict(): Verdict
    {
        $log = Path::of('src/Log.php');
        $mutant = Mutant::of(
            MutantId::hash($log, 'Plus', "-a\n+b\n", 0),
            'native-log',
            Location::of($log, Line::of(1), Line::of(1)),
            Mutation::of('Plus', MutatorFamily::Arithmetic, "-a\n+b\n"),
            MutantStatus::Survived,
            Unmeasured::duration(),
        );
        $tree = TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::of(JudgedUnit::of(Unit::held($log, Group::named('holds:src/Log.php')), Origin::Run)),
            JudgedMutants::of(JudgedMutant::of($mutant, MutantJudgement::Survived)->judgedBy(self::ids('A'))),
            Uncovered::Count,
        );
        $coverage = CoverageMap::empty()->covered($log, Line::of(1), self::id('A'))->covered($log, Line::of(1), self::id('C'));

        return Verdict::of(TreeVerdicts::of($tree))->withMatrix(
            KillMatrix::of(MatrixKind::Full, $coverage)->named(
                TestNames::none()->with(self::id('A'), self::name('A'))->with(self::id('C'), self::name('C')),
            ),
        );
    }

    /**
     * A full matrix of three mutants of `src/Money.php`, lines 1 to 3, each
     * covered by all three of `F`, `G` and `H`: `F` kills lines 1 and 2 in
     * 0.2 seconds, and `G` and `H` each kill line 3 in 0.1: all three keep
     * kills at the same rate, `G` and `H` the faster, and alike.
     */
    public static function tiedVerdict(): Verdict
    {
        $money = Path::of('src/Money.php');
        $coverage = CoverageMap::empty();
        $names = TestNames::none();

        foreach (['F', 'G', 'H'] as $test) {
            $names = $names->with(self::id($test), self::name($test));

            foreach ([1, 2, 3] as $line) {
                $coverage = $coverage->covered($money, Line::of($line), self::id($test));
            }
        }

        $coverage = $coverage->timed(self::id('F'), Seconds::of(0.2))->timed(self::id('G'), Seconds::of(0.1))->timed(self::id('H'), Seconds::of(0.1));
        $tree = TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(
                self::mutant(1, MutantStatus::Killed, self::ids('F')),
                self::mutant(2, MutantStatus::Killed, self::ids('F')),
                self::mutant(3, MutantStatus::Killed, self::ids('H', 'G')),
            ),
            Uncovered::Count,
        );

        return Verdict::of(TreeVerdicts::of($tree))->withMatrix(KillMatrix::of(MatrixKind::Full, $coverage)->named($names));
    }

    /**
     * A full matrix of three mutants of `src/Money.php`: line 1 killed by `A`
     * and by `D`, which no coverage run timed, and lines 2 and 3 timed out,
     * covered by `E` and `G` alone.
     */
    public static function untimedVerdict(): Verdict
    {
        $money = Path::of('src/Money.php');
        $coverage = CoverageMap::empty()
            ->covered($money, Line::of(1), self::id('A'))
            ->covered($money, Line::of(1), self::id('D'))
            ->covered($money, Line::of(2), self::id('E'))
            ->covered($money, Line::of(3), self::id('G'))
            ->timed(self::id('A'), Seconds::of(0.4))
            ->timed(self::id('E'), Seconds::of(0.1))
            ->timed(self::id('G'), Seconds::of(0.1));
        $names = TestNames::none();

        foreach (['A', 'D', 'E', 'G'] as $test) {
            $names = $names->with(self::id($test), self::name($test));
        }

        $tree = TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(
                self::mutant(1, MutantStatus::Killed, self::ids('A', 'D')),
                self::mutant(2, MutantStatus::TimedOut, TestIds::none()),
                self::mutant(3, MutantStatus::TimedOut, TestIds::none()),
            ),
            Uncovered::Count,
        );

        return Verdict::of(TreeVerdicts::of($tree))->withMatrix(KillMatrix::of(MatrixKind::Full, $coverage)->named($names));
    }

    public static function id(string $test): TestId
    {
        return TestId::of(sprintf('Tests\\%sTest::test', $test));
    }

    public static function name(string $test): TestName
    {
        return TestName::in(Path::of(sprintf('tests/%sTest.php', $test)), sprintf('it does %s', $test));
    }

    public static function mutantAt(int $line): MutantId
    {
        return MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf("-a%d\n+b%d\n", $line, $line), 0);
    }

    private static function matrix(MatrixKind $kind): KillMatrix
    {
        $money = Path::of('src/Money.php');
        $coverage = CoverageMap::empty()
            ->covered($money, Line::of(1), self::id('A'))
            ->covered($money, Line::of(1), self::id('B0'))
            ->covered($money, Line::of(2), self::id('A'))
            ->covered($money, Line::of(2), self::id('B0'))
            ->covered($money, Line::of(3), self::id('B1'))
            ->covered($money, Line::of(3), self::id('C'))
            ->covered($money, Line::of(4), self::id('C'))
            ->covered($money, Line::of(4), self::id('D'))
            ->covered($money, Line::of(5), self::id('E'))
            ->timed(self::id('A'), Seconds::of(0.4))
            ->timed(self::id('B0'), Seconds::of(0.5))
            ->timed(self::id('B1'), Seconds::of(0.5))
            ->timed(self::id('C'), Seconds::of(0.2))
            ->timed(self::id('E'), Seconds::of(0.1));
        $names = TestNames::none();

        foreach (['A', 'C', 'D', 'E'] as $test) {
            $names = $names->with(self::id($test), self::name($test));
        }

        $names = $names
            ->with(self::id('B0'), TestRow::of(self::name('B'), '#0'))
            ->with(self::id('B1'), TestRow::of(self::name('B'), '#1'));

        return KillMatrix::of($kind, $coverage)->named($names);
    }

    private static function mutant(int $line, MutantStatus $status, TestIds $killers): JudgedMutant
    {
        $path = Path::of('src/Money.php');
        $mutant = Mutant::of(
            self::mutantAt($line),
            sprintf('native-%d', $line),
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf("-a%d\n+b%d\n", $line, $line)),
            $status,
            Unmeasured::duration(),
        )->killedBy($killers);

        return JudgedMutant::of($mutant, MutantJudgement::reported($status));
    }

    private static function ids(string ...$tests): TestIds
    {
        $ids = TestIds::none();

        foreach ($tests as $test) {
            $ids = $ids->with(self::id($test));
        }

        return $ids;
    }
}
