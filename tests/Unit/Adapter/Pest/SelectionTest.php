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

it('fits while the filter argument is shorter than the ceiling, in bytes', function (): void {
    $test = static fn(int $length): string => sprintf('P\A::__pest_evaluable_%s', str_repeat('x', $length));
    $under = $test(Ceiling::BYTES - 19);
    $at = $test(Ceiling::BYTES - 18);

    expect(Selection::of(coveringTests($under, $under, 'LegacySpec::decrements'))->fits())->toBeTrue()
        ->and(mb_strlen(Selection::of(coveringTests($under))->argument(), '8bit'))->toBe(Ceiling::BYTES - 1)
        ->and(Selection::of(coveringTests($at))->fits())->toBeFalse()
        ->and(Selection::of(coveringTests())->fits())->toBeTrue();
});
