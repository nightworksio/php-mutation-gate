<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\ThreeWords;

it('names every word it takes, the last after "or"', function (): void {
    expect(Enumerated::of(ThreeWords::cases())->expected())->toBe('"one", "two" or "three"');
});

it('reads a word into its case, and refuses any other', function (): void {
    $words = Enumerated::of(ThreeWords::cases());
    $read = static fn(string $json): Node => Node::config($json)->field('word');

    expect($words->read($read('{"word": "two"}'))->value())->toBe(ThreeWords::Two)
        ->and($words->read($read('{"word": "four"}'))->problems())
        ->toEqual([Problem::at('word', 'expected "one", "two" or "three", got "four"')])
        ->and($words->read($read('{"word": 2}'))->problems())
        ->toEqual([Problem::at('word', 'expected "one", "two" or "three", got 2')])
        ->and($words->read($read('{}'))->value())->toEqual(Absent::setting());
});
