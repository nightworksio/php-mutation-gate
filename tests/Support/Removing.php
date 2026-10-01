<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function explode;
use function file_get_contents;

use NightWorksIO\MutationGate\Core\Assertion\TestFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Removal\Removals;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree as SourceTree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use RuntimeException;

use function sprintf;
use function str_contains;

/**
 * A cart whose `add()` calls, one statement at a time, what a removal may
 * suggest deleting, the files its callees are declared in, and the tests
 * that judge it: `it records` asserts a value, `it only runs` only that the
 * cart is there, and `it asks a helper` calls one its file declares.
 */
final class Removing
{
    public const string CART = 'src/Cart.php';

    public const string LOG = 'src/Audit/Log.php';

    public const string TALLY = 'src/tally.php';

    public const string FUNCTIONS = 'src/functions.php';

    public const string TESTS = 'tests/CartTest.php';

    /** Each file, by its path, and the fixture it is read from. */
    private const array FIXTURES = [
        self::CART => 'Cart.txt',
        self::LOG => 'Log.txt',
        self::TALLY => 'tally.txt',
        self::FUNCTIONS => 'functions.txt',
        self::TESTS => 'CartTest.txt',
    ];

    public static function strong(): TestId
    {
        return TestId::of('P\Tests\CartTest::__pest_evaluable_it_records');
    }

    public static function weak(): TestId
    {
        return TestId::of('P\Tests\CartTest::__pest_evaluable_it_only_runs');
    }

    public static function helped(): TestId
    {
        return TestId::of('P\Tests\CartTest::__pest_evaluable_it_asks_a_helper');
    }

    /** The survived removal of a statement of the cart's `add()`, as written. */
    public static function removal(string $statement, Judged $judgement = Judged::Survived, Family $family = Family::RemovedCall): JudgedMutant
    {
        return JudgedMutant::of(Verdicts::mutant(
            sprintf('%s:%d', self::CART, self::lineOf(self::CART, $statement)),
            'RemoveMethodCall',
            $family,
            Verdicts::diff($statement, ''),
        ), $judgement);
    }

    /** A mutant of a line of a file, judged so; the mutator's name tells mutants of one line apart. */
    public static function mutantOf(string $file, string $line, Judged $judgement, string $mutator = 'Mutator'): JudgedMutant
    {
        return JudgedMutant::of(Verdicts::mutant(
            sprintf('%s:%d', $file, self::lineOf($file, $line)),
            $mutator,
            Family::None,
            Verdicts::diff($line, 'return;'),
        ), $judgement);
    }

    /**
     * The survivors of each callee's body but `checked()`'s: `record()`,
     * `Log::write()`, the namespace's `tally()`, the global `total()`, the
     * anonymous class's `note()` and the trait's `bump()`, and a kill of the
     * global `tally()`.
     *
     * @return list<JudgedMutant>
     */
    public static function bodies(): array
    {
        return [
            self::mutantOf(self::CART, '$this->total += $amount;', Judged::Survived),
            self::mutantOf(self::LOG, 'echo $amount;', Judged::Survived),
            self::mutantOf(self::TALLY, 'return $amount + 1;', Judged::Survived),
            self::mutantOf(self::FUNCTIONS, 'return $amount - 1;', Judged::Killed),
            self::mutantOf(self::FUNCTIONS, 'return $amount * 2;', Judged::Survived),
            self::mutantOf(self::CART, 'echo 1;', Judged::Survived),
            self::mutantOf(self::CART, 'echo 2;', Judged::Survived),
        ];
    }

    /**
     * What the removal finds, among these other mutants, judged by these
     * tests where the removal's line is covered by them.
     *
     * @param list<JudgedMutant> $others
     */
    public static function found(JudgedMutant $removal, array $others, TestId ...$tests): Removable|NoFinding
    {
        $found = Removals::findings(self::trees($removal, ...$others), self::matrix($removal, ...$tests), self::sources(), self::testFiles());
        $finding = $found->of($removal->mutant()->id());

        return $finding instanceof Removable ? $finding : NoFinding::survivor();
    }

    /** The removal of `$this->record()`, and every mutant of the callees' bodies, judged as the gate judges them. */
    public static function verdict(): Verdict
    {
        $removal = self::removal('$this->record($amount);');
        $trees = self::trees($removal, ...self::bodies());
        $matrix = self::matrix($removal, self::strong());

        return Verdict::of($trees->found(Removals::findings($trees, $matrix, self::sources(), self::testFiles())))->withMatrix($matrix);
    }

    public static function trees(JudgedMutant ...$mutants): TreeVerdicts
    {
        return TreeVerdicts::of(TreeVerdict::judged(
            SourceTree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(...$mutants),
            Uncovered::Count,
        ));
    }

    /** The removal's line covered by these tests, each named in the cart's test file. */
    public static function matrix(JudgedMutant $removal, TestId ...$tests): KillMatrix
    {
        $coverage = CoverageMap::empty();

        foreach ($tests as $test) {
            $coverage = $coverage->covered(Path::of(self::CART), $removal->mutant()->location()->start(), $test);
        }

        return KillMatrix::of(MatrixKind::FirstKiller, $coverage)->named(TestNames::none()
            ->with(self::strong(), TestName::in(Path::of(self::TESTS), 'it records'))
            ->with(self::weak(), TestName::in(Path::of(self::TESTS), 'it only runs'))
            ->with(self::helped(), TestName::in(Path::of(self::TESTS), 'it asks a helper')));
    }

    /** The source files, by their paths. */
    public static function sources(): ByPath
    {
        $sources = ByPath::none();

        foreach ([self::CART, self::LOG, self::TALLY, self::FUNCTIONS] as $file) {
            $sources = $sources->with(Path::of($file), self::contentsOf($file));
        }

        return $sources;
    }

    public static function testFiles(): TestFiles
    {
        return TestFiles::read(ByPath::none()->with(Path::of(self::TESTS), self::contentsOf(self::TESTS)), ByPath::none());
    }

    /** The first line of a file that holds some code, from 1. */
    public static function lineOf(string $file, string $code): int
    {
        foreach (explode("\n", self::contentsOf($file)->text()) as $at => $line) {
            if (str_contains($line, $code)) {
                return $at + 1;
            }
        }

        throw new RuntimeException(sprintf('%s holds no %s.', $file, $code));
    }

    private static function contentsOf(string $file): Contents
    {
        return Contents::of((string) file_get_contents(Tree::at(sprintf('tests/Fixtures/Removal/%s', self::FIXTURES[$file]))));
    }
}
