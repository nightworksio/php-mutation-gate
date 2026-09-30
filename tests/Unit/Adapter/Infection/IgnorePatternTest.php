<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\AnyMutator;
use NightWorksIO\MutationGate\Adapter\Infection\IgnorePattern;

it('holds a pattern over names, with the key it is under and the mutator it applies to', function (): void {
    $pattern = IgnorePattern::overNames('Plus.ignore', 'Plus', 'App\\Money::*');

    expect($pattern->key())->toBe('Plus.ignore')
        ->and($pattern->mutator())->toBe('Plus')
        ->and($pattern->pattern())->toBe('App\\Money::*')
        ->and($pattern->isOverSource())->toBeFalse();
});

it('holds a pattern over source, which may apply to every mutator', function (): void {
    $pattern = IgnorePattern::overSource('global-ignoreSourceCodeByRegex', AnyMutator::of(), 'Log::.*');

    expect($pattern->mutator())->toEqual(AnyMutator::of())
        ->and($pattern->isOverSource())->toBeTrue();
});
