<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Reach\AffectedTest;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Affecting;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\ReadingTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SourceTests;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function str_starts_with;

/**
 * A project the `affected` rules run over: `src/Money.php` declares a rate
 * that `src/Price.php` reads on its line 7 and `tests/RateTest.php` reads
 * itself; `tests/MoneyTest.php` runs Money's line 11, `tests/RateTest.php` its line 9, `tests/PriceTest.php`
 * runs Price's line 7 through `tests/Support/Builds.php`, and CI runs the
 * gate from `.github/workflows/gate.yml`. The runner selects `tests/RateTest.php`
 * to judge `src/Lone.php`, which no test runs. Templates are ignored by proofs.
 */
final class Affected
{
    public const array FILES = [
        'src/Money.php' => "<?php\n\nnamespace App;\n\nfinal class Money\n{\n    public const RATE = 2;\n\n"
            . "    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }\n}\n",
        'src/Price.php' => "<?php\n\nnamespace App;\n\nfinal class Price\n{\n    public function total(int \$n): int"
            . " { return \$n * Money::RATE; }\n}\n",
        'src/Lone.php' => "<?php\n\nnamespace App;\n\nfinal class Lone\n{\n    public function one(): int\n    {\n"
            . "        return 1;\n    }\n}\n",
        'tests/MoneyTest.php' => "<?php\n\nuse App\\Money;\n\nit('adds', fn () => expect(new Money()->add(1, 1))->toBe(2));\n"
            . "it('adds nothing', fn () => expect(new Money()->add(1, 0))->toBe(1));\n",
        'tests/PriceTest.php' => "<?php\n\nuse Tests\\Support\\Builds;\n\nit('totals', fn () => expect(Builds::price()->total(2))"
            . "->toBe(4));\n",
        'tests/RateTest.php' => "<?php\n\nuse App\\Money;\n\nit('rates', fn () => expect(Money::RATE)->toBe(2));\n",
        'tests/Support/Builds.php' => "<?php\n\nnamespace Tests\\Support;\n\nuse App\\Price;\n\nfinal class Builds\n{\n"
            . "    public static function price(): Price { return new Price(); }\n}\n",
    ];

    /**
     * The answer for these changes, the project's files on disk as these say
     * and as they were at the base so, the scan reading these files besides,
     * which cannot be read for anything else.
     *
     * @param array<string, string> $now
     * @param array<string, string> $before
     * @param array<string, string> $unread
     * @param string                ...$alike the changed files that decide how the gate runs as they did at the base
     */
    public static function to(
        Changes $changes,
        array $now = self::FILES,
        array $before = self::FILES,
        array $unread = [],
        string ...$alike,
    ): AffectedTests {
        $sources = self::sources($now, $before);

        foreach ($alike as $path) {
            $sources = $sources->decidingAlike(Path::of($path));
        }

        $layout = Layout::standard(Paths::none())->runBy(Glob::of('.github/workflows/gate.yml'));
        $trees = self::trees();
        $users = SupportUsers::in($layout, Packages::of($trees), $sources);
        $places = self::places();
        $readers = new ReadingTests($places, self::map(), self::codebase([...$now, ...$unread]), $users, $sources);

        return new Affecting(
            $layout,
            $trees,
            Globs::of(Glob::of('templates/**')),
            new SourceTests($places, self::judges(), self::map(), $readers, $users, $sources),
            $users,
        )->of($changes, $sources, $places);
    }

    /** The one tree, `src`. */
    public static function trees(): Trees
    {
        return Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())));
    }

    /** Each test file with the map's tests in it. */
    public static function places(): TestPlaces
    {
        return TestPlaces::none()
            ->placing(Path::of('tests/MoneyTest.php'), self::ids('MoneyTest::adds', 'MoneyTest::adds nothing'))
            ->placing(Path::of('tests/PriceTest.php'), self::ids('PriceTest::totals'))
            ->placing(Path::of('tests/RateTest.php'), self::ids('RateTest::rates'));
    }

    /** What the tests run: Money's line 11 by both of MoneyTest's tests, its line 9 by RateTest's, Price's line 7 by PriceTest's. */
    public static function map(): CoverageMap
    {
        return CoverageMap::empty()
            ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
            ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds nothing'))
            ->covered(Path::of('src/Price.php'), Line::of(7), TestId::of('PriceTest::totals'))
            ->covered(Path::of('src/Money.php'), Line::of(9), TestId::of('RateTest::rates'));
    }

    /** @return list<array{string, list<string>, list<string>}> each test file listed, with its tests' ids and its reasons */
    public static function listed(AffectedTests $affected): array
    {
        $listed = [];

        foreach ($affected->tests() as $test) {
            $listed[] = self::row($test);
        }

        return $listed;
    }

    /**
     * @param iterable<Reason> $reasons
     *
     * @return list<string>
     */
    public static function texts(iterable $reasons): array
    {
        $texts = [];

        foreach ($reasons as $reason) {
            $texts[] = $reason->text();
        }

        return $texts;
    }

    public static function ids(string ...$ids): TestIds
    {
        $tests = TestIds::none();

        foreach ($ids as $id) {
            $tests = $tests->with(TestId::of($id));
        }

        return $tests;
    }

    /** @return array{string, list<string>, list<string>} */
    private static function row(AffectedTest $test): array
    {
        $ids = [];

        foreach ($test->tests() as $id) {
            $ids[] = $id->value();
        }

        return [$test->file()->value(), $ids, self::texts($test->reasons())];
    }

    private static function judges(): Judges
    {
        return Judges::none()
            ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')))
            ->judging(Path::of('src/Price.php'), Paths::of(Path::of('tests/PriceTest.php')))
            ->judging(Path::of('src/Lone.php'), Paths::of(Path::of('tests/RateTest.php')));
    }

    /** @param array<string, string> $now */
    private static function codebase(array $now): Codebase
    {
        $sources = [];

        foreach ($now as $path => $text) {
            $sources[] = Source::read(Path::of($path), Contents::of($text), test: str_starts_with($path, 'tests/'));
        }

        return Codebase::of(...$sources);
    }

    /**
     * @param array<string, string> $now
     * @param array<string, string> $before
     */
    private static function sources(array $now, array $before): Sources
    {
        $sources = Sources::none();

        foreach ($now as $path => $text) {
            $sources = $sources->withNow(Path::of($path), Contents::of($text));
        }

        foreach ($before as $path => $text) {
            $sources = $sources->withBefore(Path::of($path), Contents::of($text));
        }

        return $sources;
    }
}
