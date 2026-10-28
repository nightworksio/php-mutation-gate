<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ExactRows;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project of two Pest test files, and a map in which their tests cover src/Money.php.
 *
 * @return array{TestFiles, CoverageMap, TestIds} the project's test files, the map and its tests
 */
function exactRowsOver(): array
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/MoneySpec.php', "<?php\nit('adds', fn () => true);\nit('takes', fn () => true);\n");
    Scratch::write($root, 'tests/WalletSpec.php', "<?php\nit('fills', fn () => true);\n");
    $tests = TestIds::of(
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'),
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"'),
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_takes'),
        TestId::of('P\Tests\WalletSpec::__pest_evaluable_it_fills'),
    );
    $map = CoverageMap::empty();

    foreach ($tests as $test) {
        $map = $map->covered(Path::of('src/Money.php'), Line::of(3), $test);
    }

    return [new TestFiles(Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'))), $map, $tests];
}

it('runs the tests of a map its exact filter selects, by that filter, where the run passed it', function (): void {
    [$files, $map] = exactRowsOver();
    $judging = TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'));
    $selection = Selection::exactly($judging, Suite::of($map->tests()));

    expect(new ExactRows($files)->of($judging, $map, NotGiven::value()))->toEqual(RunTests::filtered($judging, $selection->filter()));
});

it('runs every test of the test files a run loaded with no filter, or of every one, by those files', function (): void {
    [$files, $map, $tests] = exactRowsOver();
    $judging = TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_takes'));
    $money = Paths::of(Path::of('tests/MoneySpec.php'));

    expect(new ExactRows($files)->of($judging, $map, $money))->toEqual(RunTests::loading(TestIds::of(...array_slice([...$tests], 0, 3)), $money))
        ->and(new ExactRows($files)->of($judging, $map, WholeSuite::tests()))->toEqual(RunTests::loading($tests, WholeSuite::tests()));
});

it('runs every test of a map by every test file where its filter cannot pass and the run said nothing of it', function (): void {
    [$files] = exactRowsOver();
    $long = TestIds::of(...array_map(static fn(int $at): TestId => TestId::of(sprintf('P\Tests\MoneySpec::__pest_evaluable_it_adds_%d_%s', $at, str_repeat('x', 300))), range(1, 400)));
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), ...$long)
        ->covered(Path::of('src/Other.php'), Line::of(3), TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_rounds'));

    expect(new ExactRows($files)->of($long, $map, NotGiven::value()))->toEqual(RunTests::loading($map->tests(), WholeSuite::tests()));
});

it('runs every test of a map its filter selects, though not among the tests that judge the mutant', function (): void {
    [$files, $map] = exactRowsOver();
    $big = TestId::of('P\Tests\BigMoneySpec::__pest_evaluable_it_counts');
    $judging = TestIds::of(
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'),
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"'),
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_takes'),
    );
    $run = new ExactRows($files)->of($judging, $map->covered(Path::of('src/Other.php'), Line::of(1), $big), NotGiven::value());

    expect($run->by())->toEqual(Filter::matching('"MoneySpec::"'))
        ->and([...$run->tests()])->toEqual([...$judging, $big]);
});

it('counts each map\'s tests apart, asked over one map and then another', function (): void {
    [$files, $map] = exactRowsOver();
    $rows = new ExactRows($files);
    $judging = TestIds::of(TestId::of('P\Tests\WalletSpec::__pest_evaluable_it_fills'));
    $wider = $map->covered(Path::of('src/Other.php'), Line::of(1), TestId::of('P\Tests\WalletSpec::__pest_evaluable_it_saves'));

    expect($rows->of($judging, $map, NotGiven::value())->by())->toEqual(Filter::matching('"WalletSpec::"'))
        ->and($rows->of($judging, $wider, NotGiven::value())->by())->toEqual(Filter::matching('"WalletSpec::(.*)it.fills"'));
});
