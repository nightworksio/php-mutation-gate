<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$ids = static fn(TestIds $tests): array => array_map(static fn(TestId $test): string => $test->value(), iterator_to_array($tests, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(TestIds::none())->toHaveCount(0);
});

it('keeps each test once, in the order it came', function () use ($ids): void {
    $tests = TestIds::of(TestId::of('B::b'), TestId::of('1'), TestId::of('B::b'));

    expect($ids($tests))->toBe(['B::b', '1'])
        ->and($tests)->toHaveCount(2);
});

it('adds a test without changing the tests it came from', function (): void {
    $tests = TestIds::none();

    expect($tests->with(TestId::of('A::a')))->toHaveCount(1)
        ->and($tests)->toHaveCount(0);
});

it('says whether it holds a test', function (): void {
    $tests = TestIds::of(TestId::of('A::a'));

    expect($tests->has(TestId::of('A::a')))->toBeTrue()
        ->and($tests->has(TestId::of('A::b')))->toBeFalse();
});

it('joins two sets in order, keeps those another holds, and leaves out those it holds, each in this order', function () use ($ids): void {
    $tests = TestIds::of(TestId::of('A::a'), TestId::of('B::b'), TestId::of('C::c'));
    $others = TestIds::of(TestId::of('C::c'), TestId::of('D::d'), TestId::of('A::a'));

    expect($ids($tests->and($others)))->toBe(['A::a', 'B::b', 'C::c', 'D::d'])
        ->and($ids($tests->among($others)))->toBe(['A::a', 'C::c'])
        ->and($ids($tests->without($others)))->toBe(['B::b'])
        ->and($ids($tests))->toBe(['A::a', 'B::b', 'C::c']);
});

it('joins, keeps and leaves out tests in time linear in their number', function (): void {
    $sets = static function (int $size): Closure {
        $tests = TestIds::of(...array_map(static fn(int $at): TestId => TestId::of(sprintf('T%d::t', $at)), range(1, $size)));
        $half = TestIds::of(...array_map(static fn(int $at): TestId => TestId::of(sprintf('T%d::t', $at * 2)), range(1, $size)));

        return static fn(): int => count($tests->and($half)) + count($tests->among($half)) + count($tests->without($half));
    };

    expect($sets(10)())->toBe(15 + 5 + 5)
        ->and(Growth::of(4000, $sets))->toBeLessThan(Growth::LINEAR);
});

it('collects tests in time linear in their number', function (): void {
    $tests = static function (int $size): Closure {
        $each = array_map(static fn(int $at): TestId => TestId::of(sprintf('T%d::t', $at)), range(1, $size));

        return static fn(): TestIds => TestIds::of(...$each, ...$each);
    };

    expect($tests(10)())->toHaveCount(10)
        ->and(Growth::of(2500, $tests))->toBeLessThan(Growth::LINEAR);
});

it('names its first test, and none where it holds none', function (): void {
    expect(TestIds::of(TestId::of('1'), TestId::of('A::a'))->first())->toEqual(TestId::of('1'))
        ->and(TestIds::none()->first())->toEqual(NotGiven::value());
});

it('joins any number of sets, each test once, in the order the sets first name them', function () use ($ids): void {
    $sets = [TestIds::of(TestId::of('B::b'), TestId::of('A::a')), TestIds::none(), TestIds::of(TestId::of('A::a'), TestId::of('C::c'))];

    expect($ids(TestIds::union($sets)))->toBe(['B::b', 'A::a', 'C::c'])
        ->and(TestIds::union([]))->toHaveCount(0);
});

it('joins sets in time linear in all they hold', function (): void {
    $sets = static function (int $size): Closure {
        $each = array_map(static fn(int $at): TestIds => TestIds::of(TestId::of(sprintf('T%d::t', $at)), TestId::of(sprintf('T%d::t', $at + 1))), range(1, $size));

        return static fn(): int => count(TestIds::union($each));
    };

    expect($sets(10)())->toBe(11)
        ->and(Growth::of(2500, $sets))->toBeLessThan(Growth::LINEAR);
});
