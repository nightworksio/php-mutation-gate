<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\LoadingUnfiltered;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/**
 * A control of a file by these tests, allowed this long.
 *
 * @param list<string> $tests
 */
function controlOf(string $file, array $tests, float $limit): Control
{
    return Control::of(
        Path::of($file),
        TestIds::of(...array_map(TestId::of(...), $tests)),
        Seconds::of($limit),
    );
}

/** The tests of a control of src/Money.php. */
function controlTests(): TestIds
{
    return TestIds::of(TestId::of('MoneyTest::a'), TestId::of('MoneyTest::b'));
}

/** A control of src/Money.php running a run's tests, selected as it selected them, allowed five seconds. */
function controlRunning(RunTests $run): Control
{
    return Control::running(Path::of('src/Money.php'), $run, Seconds::of(5.0));
}

it('holds the file it serves unmutated, the tests it runs in their order, and the limit it is allowed', function (): void {
    $control = controlOf('src/Money.php', ['MoneyTest::b', 'MoneyTest::a'], 5.0);

    expect($control->file())->toEqual(Path::of('src/Money.php'))
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]))->toBe(['MoneyTest::b', 'MoneyTest::a'])
        ->and($control->limit())->toEqual(Seconds::of(5.0));
});

it('is told from another control by its file, its tests in their order and its limit, and by nothing else', function (Control $other, bool $same): void {
    expect(controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0)->key() === $other->key())->toBe($same);
})->with([
    'the same control' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0), true],
    'another file' => [fn(): Control => controlOf('src/Tax.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0), false],
    'another test' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::c'], 5.0), false],
    'the tests in another order' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::b', 'MoneyTest::a'], 5.0), false],
    'fewer tests' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a'], 5.0), false],
    'another limit' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 6.0), false],
    'one test whose id holds the others\' joined' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a MoneyTest::b'], 5.0), false],
    'the tests by a filter' => [fn(): Control => controlRunning(RunTests::filtered(controlTests(), Filter::matching('MoneyTest'))), false],
    'the tests by their files' => [fn(): Control => controlRunning(RunTests::loading(controlTests(), Paths::of(Path::of('tests/MoneyTest.php')))), false],
    'the tests by every file' => [fn(): Control => controlRunning(RunTests::loading(controlTests(), WholeSuite::tests())), false],
    'the tests as the runner selects any' => [fn(): Control => controlRunning(RunTests::of(controlTests())), true],
    'a mutant served' => [fn(): Control => controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0)->serving(Contents::of('<?php // mutant'), Seconds::of(2.0)), false],
]);

it('serves a mutant\'s text in its file\'s place under a silence limit as a part of a heavy mutant\'s run, and none as a control', function (): void {
    $control = controlOf('src/Money.php', ['MoneyTest::a'], 5.0);
    $part = $control->serving(Contents::of('<?php // mutant'), Seconds::of(2.0));

    expect([$control->served(), $control->silence()])->toEqual([NotGiven::value(), NotGiven::value()])
        ->and([$part->served(), $part->silence(), $part->limit(), $part->file()])
        ->toEqual([Contents::of('<?php // mutant'), Seconds::of(2.0), Seconds::of(5.0), Path::of('src/Money.php')])
        ->and($part->key())->not->toBe($control->serving(Contents::of('<?php // other'), Seconds::of(2.0))->key())
        ->and($part->key())->not->toBe($control->serving(Contents::of('<?php // mutant'), Seconds::of(3.0))->key())
        ->and($part->unmutated())->toEqual($control);
});

it('selects its tests as its mutant\'s run did: by a filter, by test files, by every one, or as the runner selects any', function (): void {
    $filter = Filter::matching('MoneyTest');
    $files = Paths::of(Path::of('tests/MoneyTest.php'));

    expect(controlRunning(RunTests::filtered(controlTests(), $filter))->selected())->toBe($filter)
        ->and(controlRunning(RunTests::loading(controlTests(), $files))->selected())->toBe($files)
        ->and(controlRunning(RunTests::loading(controlTests(), WholeSuite::tests()))->selected())->toEqual(WholeSuite::tests())
        ->and(controlOf('src/Money.php', ['MoneyTest::a'], 5.0)->selected())->toEqual(NotGiven::value())
        ->and(controlRunning(RunTests::filtered(controlTests(), Filter::matching('MoneyTest')))->key())
        ->not->toBe(controlRunning(RunTests::filtered(controlTests(), Filter::matching('TaxTest')))->key())
        ->and(controlRunning(RunTests::loading(controlTests(), $files))->key())
        ->not->toBe(controlRunning(RunTests::loading(controlTests(), Paths::of(Path::of('tests/TaxTest.php'))))->key());
});

it('gives the run that judges a mutant the test files its run loaded with no filter', function (): void {
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(1), TestId::of('MoneyTest::a'));
    $mutant = Verdicts::mutant('src/Money.php:1', 'Plus', MutatorFamily::Arithmetic, '-');
    $files = Paths::of(Path::of('tests/MoneyTest.php'));
    $echo = new LoadingUnfiltered();

    expect(Control::judging($mutant, $map, HeldCovered::none(), $echo, $files))
        ->toEqual(RunTests::loading(TestIds::of(TestId::of('MoneyTest::a')), $files))
        ->and(Control::judging($mutant, $map, HeldCovered::none(), $echo, NotGiven::value()))
        ->toEqual(RunTests::of(TestIds::of(TestId::of('MoneyTest::a'))));
});
