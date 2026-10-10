<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\TestDependencies;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * These ids' values.
 *
 * @return list<string>
 */
function dependencyIds(TestIds $tests): array
{
    return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
}

it('closes some tests over what they depend on, in turn, asking about each new test once, through a cycle', function (): void {
    $known = TestDependencies::none()
        ->with('A::c', 'A::b')
        ->with('A::b', 'A::a')
        ->with('A::a', 'A::c')
        ->and(TestDependencies::none()->with('A::b', 'B::x'));
    $asked = [];
    $closed = TestDependencies::closure(
        TestIds::of(TestId::of('A::c')),
        static function (TestIds $tests) use ($known, &$asked): TestDependencies {
            $asked[] = dependencyIds($tests);

            return $known;
        },
    );

    expect(dependencyIds($closed))->toBe(['A::c', 'A::b', 'A::a', 'B::x'])
        ->and($asked)->toBe([['A::c'], ['A::b'], ['A::a', 'B::x']]);
});

it('closes a test that depends on nothing, and an id that names no method, over nothing', function (): void {
    $known = TestDependencies::none()->with('A::b', 'A::a');
    $read = static fn(): TestDependencies => $known;

    expect(dependencyIds(TestDependencies::closure(TestIds::of(TestId::of('A::a')), $read)))->toBe(['A::a'])
        ->and(dependencyIds(TestDependencies::closure(TestIds::of(TestId::of('tests/a.phpt')), $read)))->toBe(['tests/a.phpt'])
        ->and(dependencyIds(TestDependencies::closure(TestIds::none(), $read)))->toBe([]);
});
