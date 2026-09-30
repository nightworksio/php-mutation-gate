<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Tests\Support\ThreeWords;

it('names every word it takes, the last after "or"', function (): void {
    expect(Enumerated::of(ThreeWords::cases())->expected())->toBe('"one", "two" or "three"');
});

it('reads a word into its case, and refuses any other', function (): void {
    $words = Enumerated::of(ThreeWords::cases());

    expect($words->read('two', 'word')->value())->toBe(ThreeWords::Two)
        ->and($words->read('two', 'word')->shown())->toBe('two')
        ->and($words->read('four', 'word')->problems())
        ->toEqual([Problem::at('word', 'expected "one", "two" or "three", got "four"')]);
});
