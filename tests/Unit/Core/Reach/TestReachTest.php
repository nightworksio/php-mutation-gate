<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reaching;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/** The Pest test file of money, with a helper returning this and these tests, each a line. */
function moneyTests(string $helper, string ...$tests): Contents
{
    return Contents::of(sprintf("<?php\n\nfunction money(): int\n{\n    return %s;\n}\n\n%s\n", $helper, implode("\n\n", $tests)));
}

/** The coverage map's id of a Pest test of a file of that class, by its description. */
function moneyTest(string $class, string $description): TestId
{
    return TestMethod::id($class, TestMethod::pest($description));
}

/**
 * What a change to a test file reaches, where it is read from these
 * versions, the runner judges by it what `$judges` says and places in it
 * the tests the map holds of its class.
 */
function testReached(Change $change, Contents|Missing $before, Contents|Missing $now, TestRuns|NoMap $runs): Reach
{
    $sources = Sources::none();
    $sources = $before instanceof Contents ? $sources->withBefore($change->previousPath(), $before) : $sources;
    $sources = $now instanceof Contents ? $sources->withNow($change->path(), $now) : $sources;
    $root = Package::at(Path::root());
    $judges = Judges::none()
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/Unit/MoneyTest.php')))
        ->judging(Path::of('src/Rate.php'), Paths::of(Path::of('tests/Unit/MoneyTest.php')))
        ->judging(Path::of('src/Clock.php'), Paths::of(Path::of('tests/Unit/MoneyTest.php'), Path::of('tests/Unit/ClockTest.php')))
        ->judging(Path::of('app-modules/billing/src/Invoice.php'), Paths::of(Path::of('app-modules/billing/tests/InvoiceTest.php')));

    return new Reaching(
        Layout::standard(Paths::none())
            ->testedIn(SuiteDirectory::conventional(), SuiteDirectory::of(Path::of('app-modules/billing/tests'), ''))
            ->withModule(Path::of('app-modules/billing')),
        Trees::of(
            Tree::at(Path::of('src'), Undeclared::floor(), $root),
            Tree::at(Path::of('app-modules/billing/src'), Undeclared::floor(), $root),
            Tree::at(Path::of('app-modules/billing/lib'), Undeclared::floor(), $root),
        ),
    )->of(Changes::of($change), $runs instanceof NoMap ? $runs : $judges, $sources, $runs instanceof NoMap ? TestRuns::wholeFiles() : $runs);
}

/** What the tests of `tests/Unit/MoneyTest.php` and of the billing module's invoices run, by the map. */
function moneyRuns(): TestRuns
{
    $adds = moneyTest('P\Tests\Unit\MoneyTest', 'it adds');
    $rounds = moneyTest('P\Tests\Unit\MoneyTest', 'it rounds');
    $invoices = moneyTest('P\AppModules\Billing\Tests\InvoiceTest', 'it invoices');
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 3, $adds->value(), $rounds->value()),
        CoveredLine::of(Path::of('src/Rate.php'), 5, $rounds->value()),
        CoveredLine::of(Path::of('src/Clock.php'), 2, 'P\Tests\Unit\ClockTest::__pest_evaluable_it_ticks'),
        CoveredLine::of(Path::of('app-modules/billing/src/Invoice.php'), 4, $invoices->value()),
    );

    return TestRuns::of($map, TestPlaces::none()
        ->placing(Path::of('tests/Unit/MoneyTest.php'), TestIds::of($adds, $rounds))
        ->placing(Path::of('app-modules/billing/tests/InvoiceTest.php'), TestIds::of($invoices)));
}

/** @return list<string> */
function testReasons(Reach $reach): array
{
    return array_map(static fn(Reason $reason): string => $reason->text(), [...$reach->reasons()]);
}

$adds = "it('adds', fn () => expect(money() + 1)->toBe(2));";
$rounds = "it('rounds', fn () => expect(round(money()))->toBe(1));";
$makeChange = static fn(): Change => Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::of(Line::of(9)));

it('reaches only what the tests a change touched run, read from the map by their ids', function () use ($adds, $rounds, $makeChange): void {
    $reach = testReached($makeChange(), moneyTests('1', $adds, $rounds), moneyTests('1', str_replace('+ 1', '+ 2', $adds), $rounds), moneyRuns());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Rate.php'))))->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeFalse()
        ->and(testReasons($reach))->toBe(['`tests/Unit/MoneyTest.php` changed 1 of its 2 tests, so the 1 files they run are reached.']);
});

it('reaches what all its tests run, by the map and not by the runner\'s files, where the change moved what they share', function () use ($adds, $rounds, $makeChange): void {
    $reach = testReached($makeChange(), moneyTests('1', $adds, $rounds), moneyTests('2', $adds, $rounds), moneyRuns());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Rate.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeFalse()
        ->and(testReasons($reach))->toBe(['`tests/Unit/MoneyTest.php` changed code its tests share, so the 2 files all its tests run are reached.']);
});

it('reaches nothing where a change touched none of the tests the map places', function () use ($adds, $rounds, $makeChange): void {
    $reach = testReached($makeChange(), moneyTests('1', $adds, $rounds), moneyTests('1', $adds, $rounds), moneyRuns());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and(testReasons($reach))->toBe(['`tests/Unit/MoneyTest.php` changed none of the 2 tests the coverage map places in it, so it reaches nothing by itself.']);
});

it('reaches what the runner judges by a test file whose tests the map places nowhere, and says so', function () use ($adds): void {
    $change = Change::modified(Path::of('tests/Unit/ClockTest.php'), Lines::of(Line::of(9)));
    $reach = testReached($change, moneyTests('1', $adds), moneyTests('2', $adds), moneyRuns());

    expect($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and(testReasons($reach))->toBe(['The coverage map places no test in `tests/Unit/ClockTest.php` by its id, so the 1 files the runner judges by it are reached.']);
});

it('reaches what the runner judges by the whole file where reach.changedTests asks for it', function () use ($adds, $rounds, $makeChange): void {
    $reach = testReached($makeChange(), moneyTests('1', $adds, $rounds), moneyTests('1', str_replace('+ 1', '+ 2', $adds), $rounds), TestRuns::wholeFiles());

    expect($reach->reaches(Unit::file(Path::of('src/Rate.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and(testReasons($reach))->toBe(['`tests/Unit/MoneyTest.php` changed, so the 3 files its tests run are reached.']);
});

it('reaches every tree of the module a narrowed test is in, as well as what its tests run', function () use ($adds): void {
    $change = Change::added(Path::of('app-modules/billing/tests/InvoiceTest.php'), Lines::of(Line::of(1)));
    $reach = testReached($change, Missing::at($change->path()), moneyTests('1', $adds), moneyRuns());

    expect($reach->reaches(Unit::file(Path::of('app-modules/billing/src/Invoice.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('app-modules/billing/lib/Tax.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and(testReasons($reach))->toBe([
            '`app-modules/billing/tests/InvoiceTest.php` is new, so the 1 files all its tests run are reached.',
            '`app-modules/billing/tests/InvoiceTest.php` is a test of the module app-modules/billing, so every tree of it is reached.',
        ]);
});

it('reaches every unit of its package where a narrowed test was deleted, or no map says what it runs', function (Change $change, string $said) use ($adds): void {
    $reach = testReached($change, moneyTests('1', $adds), Missing::at($change->path()), str_contains($said, 'No coverage map') ? NoMap::toRead() : moneyRuns());

    expect($reach->reachesPackage(Package::at(Path::root())))->toBeTrue()
        ->and(testReasons($reach))->toBe([$said]);
})->with([
    'deleted' => [
        fn(): Change => Change::deleted(Path::of('tests/Unit/MoneyTest.php')),
        '`tests/Unit/MoneyTest.php` was deleted, so every unit of the project is reached.',
    ],
    'no map' => [
        fn(): Change => Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::of(Line::of(9))),
        'No coverage map says what `tests/Unit/MoneyTest.php` runs, so every unit of the project is reached.',
    ],
]);
