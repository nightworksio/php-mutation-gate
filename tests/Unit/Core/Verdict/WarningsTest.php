<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

$texts = static fn(Warnings $warnings): array => array_map(static fn(Warning $warning): string => $warning->text(), iterator_to_array($warnings, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Warnings::none())->toHaveCount(0);
});

it('keeps warnings in the order they were raised, numbered from nought', function () use ($texts): void {
    expect($texts(Warnings::of(...['b' => Warning::that('b'), 'a' => Warning::that('a')])))->toBe(['b', 'a']);
});

it('adds a warning without changing the warnings it came from', function () use ($texts): void {
    $warnings = Warnings::of(Warning::that('a'));

    expect($texts($warnings->with(Warning::that('b'))))->toBe(['a', 'b'])
        ->and($warnings)->toHaveCount(1);
});
