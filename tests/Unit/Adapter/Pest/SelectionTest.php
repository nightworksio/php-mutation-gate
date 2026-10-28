<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** The tests covering a mutant, by their ids. */
function coveringTests(string ...$tests): TestIds
{
    return TestIds::of(...array_map(TestId::of(...), array_values($tests)));
}

/** Exactly these tests, of a suite that names no test, so none is held whole. */
function exactSelection(TestIds $tests): Selection
{
    return Selection::exactly($tests, Suite::none());
}

it('builds the filter pest-plugin-mutate builds, one alternative per test it can name', function (): void {
    $selection = Selection::of(coveringTests(
        'P\Tests\MoneySpec::__pest_evaluable_it_adds_two__numbers',
        'LegacySpec::decrements',
        'Tests\MoneyTest::testAdds#1',
        'Tests\MoneyTest::testAdds#2',
        'Tests\Legacy_Test::testDecrements',
    ));

    expect($selection->argument())->toBe('--filter="MoneySpec::(.*)it.adds.two.{1,2}numbers|MoneyTest::(.*)testAdds"')
        ->and($selection->count())->toBe(5);
});

it('gives its filter as pest-plugin-mutate passes it, quotes and all, for a run that selects as a mutant\'s does', function (): void {
    $selection = Selection::of(coveringTests('P\Tests\MoneySpec::__pest_evaluable_it_adds', 'Tests\MoneyTest::testAdds#1'));

    expect($selection->filter())->toEqual(Filter::matching('"MoneySpec::(.*)it.adds|MoneyTest::(.*)testAdds"'))
        ->and(sprintf('--filter=%s', $selection->filter()->pattern()))->toBe($selection->argument());
});

it('names the covering tests the filter does not select', function (): void {
    $selection = Selection::of(coveringTests(
        'P\Tests\MoneySpec::__pest_evaluable_it_adds',
        'LegacySpec::decrements',
        'Tests\MoneyTest::testAdds#1',
        'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        'Tests\RepeatTest::testRepeats (attempt 2 of 3)',
    ));

    expect($selection->unselected())->toEqual(coveringTests(
        'LegacySpec::decrements',
        'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        'Tests\RepeatTest::testRepeats (attempt 2 of 3)',
    ));
});

it('selects every test it can name, data sets included', function (): void {
    $selection = Selection::of(coveringTests('P\Tests\A::__pest_evaluable_it_works#("x")', 'Tests\B::testWorks#named'));

    expect($selection->unselected())->toEqual(TestIds::none());
});

it('names each covering test\'s class by its name within its namespace, once', function (): void {
    $selection = Selection::of(coveringTests(
        'odd',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds',
        'Tests\MoneySpec::testAdds',
        'LegacySpec::decrements',
        'MoneySpec::subtracts',
    ));

    expect($selection->classes())->toBe(['MoneySpec', 'LegacySpec']);
});

it('fits while the filter argument is shorter than the ceiling, in bytes, where PCRE compiles it', function (): void {
    $test = static fn(int $length): string => sprintf('P\A::%s%s', str_repeat('\Q\E', intdiv($length, 4)), str_repeat('x', $length % 4));
    $under = $test(Ceiling::BYTES - 19);
    $at = $test(Ceiling::BYTES - 18);

    expect(Selection::of(coveringTests($under, $under, 'LegacySpec::decrements'))->fits())->toBeTrue()
        ->and(mb_strlen(Selection::of(coveringTests($under))->argument(), '8bit'))->toBe(Ceiling::BYTES - 1)
        ->and(Selection::of(coveringTests($at))->fits())->toBeFalse()
        ->and(Selection::of(coveringTests($at))->filter()->compiles())->toBeTrue()
        ->and(Selection::of(coveringTests())->fits())->toBeTrue();
});

it('does not fit where PCRE cannot compile the filter, however short the argument', function (): void {
    $tests = array_map(static fn(int $at): string => sprintf('P\Tests\Unit\MoneyTest::__pest_evaluable_it_adds_%d', $at), range(1, 2000));
    $selection = Selection::of(coveringTests(...$tests));

    expect(Ceiling::admits($selection->argument()))->toBeTrue()
        ->and($selection->fits())->toBeFalse()
        ->and(Selection::passable($selection->argument()))->toBeFalse()
        ->and(Selection::passable('--filter="MoneyTest::(.*)adds"'))->toBeTrue();
});

it('selects exactly the rows of a data set the ids name, each test\'s rows in one alternative, each row once', function (): void {
    $selection = exactSelection(coveringTests(
        'P\Tests\Contract\AdapterTest::__pest_evaluable_it_answers#dataset "Extenders::whatBecameOf"',
        'P\Tests\Contract\AdapterTest::__pest_evaluable_it_answers#dataset "Pausers::whatBecameOf"',
        'P\Tests\Other\AdapterTest::__pest_evaluable_it_answers#dataset "Pausers::whatBecameOf"',
        'Tests\MoneyTest::testAdds#1',
    ));

    expect($selection->argument())->toBe(
        '--filter="AdapterTest::(.*)it.answers with data set (?:\"dataset \"Extenders\:\:whatBecameOf\"\"|\"dataset \"Pausers\:\:whatBecameOf\"\")$'
        . '|MoneyTest::(.*)testAdds with data set (?:\#1)$"',
    )->and($selection->count())->toBe(4)
        ->and($selection->unselected())->toEqual(TestIds::none());
});

it('selects only the row it names, by PHPUnit\'s name of it, quotes, spaces and `::` in the row\'s name included', function (string $name, int $selected): void {
    $pattern = exactSelection(coveringTests(
        'P\Tests\Contract\AdapterTest::__pest_evaluable_it_answers#dataset "Extenders::what became of"',
        'Tests\MoneyTest::testAdds#1',
    ))->filter()->pattern();

    expect(preg_match($pattern, $name))->toBe($selected);
})->with([
    'the row named' => ['Tests\Contract\AdapterTest::it answers with data set "dataset "Extenders::what became of""', 1],
    'another row' => ['Tests\Contract\AdapterTest::it answers with data set "dataset "Pausers::what became of""', 0],
    'a row it starts' => ['Tests\Contract\AdapterTest::it answers with data set "dataset "Extenders::what became of" too"', 0],
    'the numbered row named' => ['Tests\MoneyTest::testAdds with data set #1', 1],
    'a numbered row it starts' => ['Tests\MoneyTest::testAdds with data set #10', 0],
]);

it('spells an `=` in a row\'s name by its code, since Pest cuts a filter\'s value at a second `=`', function (): void {
    $selection = exactSelection(coveringTests('P\Tests\PhpFileSpec::__pest_evaluable_it_reads#dataset "at its ="'));

    expect($selection->argument())->toBe('--filter="PhpFileSpec::(.*)it.reads with data set (?:\"dataset \"at its \x3D\"\")$"')
        ->and(preg_match($selection->filter()->pattern(), 'Tests\PhpFileSpec::it reads with data set "dataset "at its =""'))->toBe(1)
        ->and(preg_match($selection->filter()->pattern(), 'Tests\PhpFileSpec::it reads with data set "dataset "at its name""'))->toBe(0);
});

it('selects every row of a test whose id names no method it can read a row of', function (): void {
    expect(exactSelection(coveringTests('Tests\MoneyTest::#1'))->argument())->toBe('--filter="MoneyTest::(.*)"');
});

it('selects every row of a test an id names whole, whatever rows other ids name', function (): void {
    $selection = exactSelection(coveringTests('Tests\MoneyTest::testAdds#1', 'Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testAdds#2'));

    expect($selection->argument())->toBe('--filter="MoneyTest::(.*)testAdds"');
});

it('names unselected the tests an exact selection cannot name or does not select', function (): void {
    $selection = exactSelection(coveringTests(
        'LegacySpec::decrements',
        'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        'Tests\MoneyTest::testAdds#1',
    ));

    expect($selection->argument())->toBe('--filter="RepeatSpec::(.*)it.repeats (repetition 1 of 2)|MoneyTest::(.*)testAdds with data set (?:\#1)$"')
        ->and($selection->unselected())->toEqual(coveringTests(
            'LegacySpec::decrements',
            'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        ));
});

it('selects exactly the covering tests a patched Pest names by their ids', function (): void {
    $ids = ['P\Tests\A::__pest_evaluable_it_works#dataset "x"', 'Tests\B::testWorks'];

    expect(Selection::covering($ids, Suite::none()))->toEqual(exactSelection(coveringTests(...$ids)));
});

it('finds among a map\'s tests those its filter selects: every row of a data set where it selects every row', function (): void {
    $map = coveringTests(
        'P\Tests\A::__pest_evaluable_it_works#dataset "x"',
        'P\Tests\A::__pest_evaluable_it_works#dataset "y"',
        'P\Tests\A::__pest_evaluable_it_fails#dataset "x"',
        'Tests\B::testWorks',
    );
    $covering = coveringTests('P\Tests\A::__pest_evaluable_it_works#dataset "x"');

    expect(Selection::of($covering)->among($map))->toEqual(coveringTests(
        'P\Tests\A::__pest_evaluable_it_works#dataset "x"',
        'P\Tests\A::__pest_evaluable_it_works#dataset "y"',
    ))->and(exactSelection($covering)->among($map))->toEqual($covering);
});

it('finds none among a map\'s tests where it names no test, and every one where its filter is not passed', function (): void {
    $map = coveringTests('P\Tests\A::__pest_evaluable_it_works', 'Tests\B::testWorks');
    $many = array_map(static fn(int $at): string => sprintf('P\Tests\Unit\MoneyTest::__pest_evaluable_it_adds_%d', $at), range(1, 2000));

    expect(Selection::of(coveringTests('LegacySpec::decrements'))->among($map))->toEqual(TestIds::none())
        ->and(Selection::of(coveringTests(...$many))->among($map))->toEqual($map);
});

it('names a class whole where the tests hold every test of it the suite names, a data set whole where they hold every row of it, and each class once', function (): void {
    $suite = coveringTests(
        'P\Tests\WalletSpec::__pest_evaluable_it_fills',
        'P\Tests\WalletSpec::__pest_evaluable_it_spends#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"',
        'P\Tests\MoneySpec::__pest_evaluable_it_takes#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_takes#dataset "b"',
        'P\Tests\MoneySpec::__pest_evaluable_it_rounds',
    );
    $covering = coveringTests(
        'P\Tests\WalletSpec::__pest_evaluable_it_fills',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"',
        'P\Tests\WalletSpec::__pest_evaluable_it_spends#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"',
        'P\Tests\MoneySpec::__pest_evaluable_it_takes#dataset "a"',
    );
    $selection = Selection::exactly($covering, Suite::of($suite));

    expect($selection->argument())->toBe(
        '--filter="WalletSpec::|MoneySpec::(.*)(?:it.adds|it.takes with data set (?:\"dataset \"a\"\")$)"',
    )->and($selection->among($suite))->toEqual(coveringTests(
        'P\Tests\WalletSpec::__pest_evaluable_it_fills',
        'P\Tests\WalletSpec::__pest_evaluable_it_spends#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"',
        'P\Tests\MoneySpec::__pest_evaluable_it_takes#dataset "a"',
    ))->and(Selection::covering(array_map(static fn(TestId $test): string => $test->value(), [...$covering]), Suite::of($suite)))
        ->toEqual($selection);
});

it('names no class or data set whole where the suite names a test of it the tests do not hold, or names none', function (): void {
    $covering = coveringTests('P\Tests\WalletSpec::__pest_evaluable_it_fills', 'P\Tests\WalletSpec::__pest_evaluable_it_spends');

    expect(Selection::exactly($covering, Suite::of($covering->with(TestId::of('P\Tests\WalletSpec::__pest_evaluable_it_saves'))))->argument())
        ->toBe('--filter="WalletSpec::(.*)(?:it.fills|it.spends)"')
        ->and(exactSelection($covering)->argument())->toBe('--filter="WalletSpec::(.*)(?:it.fills|it.spends)"')
        ->and(Selection::exactly($covering, Suite::of($covering))->argument())->toBe('--filter="WalletSpec::"');
});

it('counts a test the suite names twice once, and holds no class whole by a test the suite does not name', function (): void {
    $fills = 'P\\Tests\\WalletSpec::__pest_evaluable_it_fills';
    $spends = 'P\\Tests\\WalletSpec::__pest_evaluable_it_spends';

    expect(Selection::covering([$fills], Suite::named([$fills, $fills]))->argument())->toBe('--filter="WalletSpec::"')
        ->and(Selection::covering([$fills, $spends], Suite::named([$fills]))->argument())->toBe('--filter="WalletSpec::"')
        ->and(Selection::covering([$spends], Suite::named([$fills]))->argument())->toBe('--filter="WalletSpec::(.*)it.spends"');
});
