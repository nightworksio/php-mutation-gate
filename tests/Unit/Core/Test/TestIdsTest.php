<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

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

it('collects tens of thousands of tests in linear time', function (): void {
    $each = array_map(static fn(int $at): TestId => TestId::of(sprintf('T%d::t', $at)), range(1, 20_000));
    $tests = TestIds::none();

    $seconds = Stopwatch::seconds(static function () use ($each, &$tests): void {
        $tests = TestIds::of(...$each, ...$each);
    });

    expect($tests)->toHaveCount(20_000)
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
