<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\HeldRun;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** Units held by these tests, one each. */
function heldBy(Group|Filter ...$tests): Units
{
    $units = [];

    foreach (array_values($tests) as $at => $by) {
        $units[] = Unit::held(Path::of(sprintf('src/Held%d.php', $at)), $by);
    }

    return Units::of(...$units);
}

/**
 * What each coverage run a held run asked for loads: its files, or nothing where it loads its suites.
 *
 * @return list<Paths|NotGiven>
 */
function heldRunFiles(CoverageAsked $runner): array
{
    return array_map(
        static fn(CoverageRun $run): Paths|NotGiven => $run->files(),
        array_values(array_filter($runner->asked(), static fn(object $run): bool => $run instanceof CoverageRun)),
    );
}

it('runs a group within the test files the runner lists its tests in, and nothing more', function (): void {
    $map = CoverageMap::of()->covered(Path::of('src/Held.php'), Line::of(11), TestId::of('HeldTest::doubles'));
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::of())->runningFiles($map);
    $ran = HeldRun::of(Flows::adapters(Flows::project(), [], $runner), heldBy(Group::named('holds:src/Held.php')))->coverage(Group::named('holds:src/Held.php'), Path::of('held'));

    expect($ran)->toBe($map)
        ->and(heldRunFiles($runner))->toEqual([Paths::of(Path::of('tests/HeldTest.php'))]);
});

it('runs the group over its suites where its run within its files fails', function (): void {
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::of())->runningFiles(CannotJudge::because('a helper is missing'));
    $ran = HeldRun::of(Flows::adapters(Flows::project(), [], $runner), heldBy(Group::named('holds:src/Held.php')))->coverage(Group::named('holds:src/Held.php'), Path::of('held'));

    expect($ran)->toEqual(CoverageMap::of())
        ->and(heldRunFiles($runner))->toEqual([Paths::of(Path::of('tests/HeldTest.php')), NotGiven::value()]);
});

it('runs over its suites a group whose tests the runner lists none of, and a filter', function (Group|Filter $tests): void {
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::of());
    HeldRun::of(Flows::adapters(Flows::project(), [], $runner), heldBy($tests))->coverage($tests, Path::of('held'));

    expect(heldRunFiles($runner))->toEqual([NotGiven::value()]);
})->with([
    'an empty group' => [fn(): Group => Group::named('canary')],
    'a filter' => [fn(): Filter => Filter::matching('Held')],
]);

it('runs over its suites a group one of whose tests the runner places in no file', function (): void {
    $listed = TestListing::of(TestIds::of(TestId::of('HeldTest::doubles'), TestId::of('Unnamed::test')))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of('HeldTest::doubles'), TestId::of('Unnamed::test')));
    $runner = new CoverageAsked(RunnerFake::ofTheFixture()->listingIn(Suites::all(), $listed), CoverageMap::of());
    HeldRun::of(Flows::adapters(Flows::project(), [], $runner), heldBy(Group::named('holds:src/Held.php')))->coverage(Group::named('holds:src/Held.php'), Path::of('held'));

    expect(heldRunFiles($runner))->toEqual([NotGiven::value()]);
});

it('finds each group\'s files once for all the units, each run within its own', function (): void {
    $listed = TestListing::of(TestIds::of(TestId::of('HeldTest::doubles'), TestId::of('MoneyTest::adds')))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of('HeldTest::doubles')))
        ->grouping(Group::named('holds:src/Money.php'), TestIds::of(TestId::of('MoneyTest::adds')));
    $runner = new CoverageAsked(RunnerFake::ofTheFixture()->listingIn(Suites::all(), $listed), CoverageMap::of());
    $runs = HeldRun::of(
        Flows::adapters(Flows::project(), [], $runner),
        heldBy(Group::named('holds:src/Held.php'), Group::named('holds:src/Money.php'), Filter::matching('Held')),
    );
    $runs->coverage(Group::named('holds:src/Money.php'), Path::of('held'));
    $runs->coverage(Group::named('holds:src/Held.php'), Path::of('held'));

    expect(heldRunFiles($runner))->toEqual([
        Paths::of(Path::of('tests/MoneyTest.php')),
        Paths::of(Path::of('tests/HeldTest.php')),
    ]);
});
