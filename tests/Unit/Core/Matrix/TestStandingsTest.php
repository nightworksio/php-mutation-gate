<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Standing;
use NightWorksIO\MutationGate\Core\Matrix\TestStanding;
use NightWorksIO\MutationGate\Core\Matrix\TestStandings;
use NightWorksIO\MutationGate\Core\Matrix\WholeTest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Tests\Support\Killings;

$summary = static fn(TestStandings $standings): array => array_map(
    static fn(WholeTest $test): array => [$test->test()->value(), $test->standing(), $test->judged(), count($test)],
    iterator_to_array($standings, preserve_keys: false),
);

it('says what a matrix of first killers says of each test, whole, in the order the mutants name it', function () use ($summary): void {
    expect($summary(TestStandings::of(Killings::verdict(MatrixKind::FirstKiller))))->toBe([
        ['tests/ATest.php::it does A', Standing::Useful, 2, 1],
        ['tests/BTest.php::it does B', Standing::NeverFirst, 3, 2],
        ['tests/CTest.php::it does C', Standing::NeverFirst, 2, 1],
        ['tests/DTest.php::it does D', Standing::Useful, 1, 1],
        ['tests/ETest.php::it does E', Standing::NotAssessed, 0, 1],
    ]);
});

it('settles each suspicion with a full matrix: a test joins the useless or leaves the list', function () use ($summary): void {
    expect($summary(TestStandings::of(Killings::verdict(MatrixKind::Full))))->toBe([
        ['tests/ATest.php::it does A', Standing::Useful, 2, 1],
        ['tests/BTest.php::it does B', Standing::Useful, 3, 2],
        ['tests/CTest.php::it does C', Standing::KillsNothing, 2, 1],
        ['tests/DTest.php::it does D', Standing::Useful, 1, 1],
        ['tests/ETest.php::it does E', Standing::NotAssessed, 0, 1],
    ]);
});

it('keeps each row of a data set with what it says on its own', function (): void {
    $b = iterator_to_array(TestStandings::of(Killings::verdict(MatrixKind::FirstKiller)), preserve_keys: false)[1];
    $rows = array_map(
        static fn(TestStanding $row): array => [$row->name() instanceof TestRow ? $row->name()->row() : '', $row->standing(), $row->judged()],
        iterator_to_array($b, preserve_keys: false),
    );

    expect($rows)->toBe([['#0', Standing::NeverFirst, 2], ['#1', Standing::KillsNothing, 1]]);
});

it('picks the tests that stand so', function (): void {
    $standings = TestStandings::of(Killings::verdict(MatrixKind::FirstKiller));

    expect($standings)->toHaveCount(5)
        ->and($standings->thatStand(Standing::NeverFirst))->toHaveCount(2)
        ->and($standings->thatStand(Standing::KillsNothing))->toHaveCount(0)
        ->and($standings->thatStand(Standing::NotAssessed))->toHaveCount(1);
});

it('judges a held unit\'s mutants only by the tests of its group', function (): void {
    $verdict = Killings::heldVerdict();
    $names = array_map(static fn(WholeTest $test): string => $test->test()->value(), iterator_to_array(TestStandings::of($verdict), preserve_keys: false));

    expect($names)->toBe(['tests/ATest.php::it does A']);
});

it('names a test the runner named nothing by its id', function (): void {
    $test = TestStanding::of(TestId::of('Tests\\FTest::test'), 0, 0, 0);

    expect(WholeTest::of(TestId::of('Tests\\FTest::test'), $test)->test()->value())->toBe('Tests\\FTest::test')
        ->and($test->standing())->toBe(Standing::NotAssessed)
        ->and(TestStanding::of(TestId::of('x'), 2, 0, 0)->standing())->toBe(Standing::KillsNothing)
        ->and(TestStanding::of(TestId::of('x'), 2, 0, 1)->standing())->toBe(Standing::NeverFirst)
        ->and(TestStanding::of(TestId::of('x'), 2, 1, 1)->standing())->toBe(Standing::Useful);
});
