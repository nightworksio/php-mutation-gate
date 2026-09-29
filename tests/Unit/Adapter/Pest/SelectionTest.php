<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Selection;

it('builds the filter pest-plugin-mutate builds, one alternative per test it can name', function (): void {
    $selection = Selection::of([
        'P\Tests\MoneySpec::__pest_evaluable_it_adds_two__numbers',
        'LegacySpec::decrements',
        'Tests\MoneyTest::testAdds#1',
        'Tests\MoneyTest::testAdds#2',
        'Tests\Legacy_Test::testDecrements',
    ]);

    expect($selection->argument())->toBe('--filter="MoneySpec::(.*)it.adds.two.{1,2}numbers|MoneyTest::(.*)testAdds"')
        ->and($selection->count())->toBe(5);
});

it('names the covering tests the filter does not select', function (): void {
    $selection = Selection::of([
        'P\Tests\MoneySpec::__pest_evaluable_it_adds',
        'LegacySpec::decrements',
        'Tests\MoneyTest::testAdds#1',
        'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        'Tests\RepeatTest::testRepeats (attempt 2 of 3)',
    ]);

    expect($selection->unselected())->toBe([
        'LegacySpec::decrements',
        'P\Tests\RepeatSpec::__pest_evaluable_it_repeats (repetition 1 of 2)',
        'Tests\RepeatTest::testRepeats (attempt 2 of 3)',
    ]);
});

it('selects every test it can name, data sets included', function (): void {
    $selection = Selection::of(['P\Tests\A::__pest_evaluable_it_works#("x")', 'Tests\B::testWorks#named']);

    expect($selection->unselected())->toBe([]);
});

it('names each covering test\'s class by its name within its namespace, once', function (): void {
    $selection = Selection::of([
        'odd',
        'P\Tests\MoneySpec::__pest_evaluable_it_adds',
        'Tests\MoneySpec::testAdds',
        'LegacySpec::decrements',
    ]);

    expect($selection->classes())->toBe(['MoneySpec', 'LegacySpec']);
});

it('fits while the filter argument is shorter than the ceiling, in bytes', function (): void {
    $test = static fn(int $length): string => sprintf('P\A::__pest_evaluable_%s', str_repeat('x', $length));
    $under = $test(Selection::CEILING - 19);
    $at = $test(Selection::CEILING - 18);

    expect(Selection::of([$under, $under, 'LegacySpec::decrements'])->fits())->toBeTrue()
        ->and(mb_strlen(Selection::of([$under])->argument(), '8bit'))->toBe(Selection::CEILING - 1)
        ->and(Selection::of([$at])->fits())->toBeFalse()
        ->and(Selection::of([])->fits())->toBeTrue();
});
