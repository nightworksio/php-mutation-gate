<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** The tests covering a mutant, by their ids. */
function coveringTests(string ...$tests): TestIds
{
    return TestIds::of(...array_map(TestId::of(...), array_values($tests)));
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
