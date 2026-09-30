<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;

$texts = static fn(Failures $failures): array => array_map(static fn(Failure $failure): string => $failure->text(), iterator_to_array($failures, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Failures::none())->toHaveCount(0);
});

it('keeps failures in the order they were found, numbered from nought', function () use ($texts): void {
    expect($texts(Failures::of(...['b' => Failure::that('b'), 'a' => Failure::that('a')])))->toBe(['b', 'a']);
});

it('adds a failure without changing the failures it came from', function () use ($texts): void {
    $failures = Failures::of(Failure::that('a'));

    expect($texts($failures->with(Failure::that('b'))))->toBe(['a', 'b'])
        ->and($failures)->toHaveCount(1);
});

it('joins these failures and those, these first, without changing either', function () use ($texts): void {
    $these = Failures::of(Failure::that('a'));
    $those = Failures::of(Failure::that('b'), Failure::that('c'));

    expect($texts($these->and($those)))->toBe(['a', 'b', 'c'])
        ->and($these)->toHaveCount(1)
        ->and($those)->toHaveCount(2);
});

it('says every failure, one to a line, and nothing where there is none', function (): void {
    expect(Failures::of(Failure::that('a'), Failure::that("b\nc"))->text())->toBe("a\nb\nc")
        ->and(Failures::none()->text())->toBe('');
});
