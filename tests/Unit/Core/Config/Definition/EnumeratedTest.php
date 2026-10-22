<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
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

it('names, reads and refuses whole numbers as it does words', function (): void {
    $confidences = Enumerated::of(Confidence::cases());
    $read = static fn(string $json): Node => Node::config($json)->field('confidence');

    expect($confidences->expected())->toBe('90, 95 or 99')
        ->and($confidences->read($read('{"confidence": 99}'))->value())->toBe(Confidence::NinetyNine)
        ->and($confidences->read($read('{"confidence": "99"}'))->problems())
        ->toEqual([Problem::at('confidence', 'expected 90, 95 or 99, got "99"')])
        ->and($confidences->read($read('{"confidence": 99.0}'))->problems())
        ->toEqual([Problem::at('confidence', 'expected 90, 95 or 99, got 99.0')])
        ->and($confidences->schema()->line())->toBe('{"enum":[90,95,99]}');
});

it('reads an empty word into the case it names, and refuses what is no word or number', function (): void {
    $units = Enumerated::of(MemoryUnit::cases());
    $read = static fn(string $json): Node => Node::config($json)->field('unit');

    expect($units->read($read('{"unit": ""}'))->value())->toBe(MemoryUnit::Bytes)
        ->and($units->read($read('{"unit": true}'))->problems())
        ->toEqual([Problem::at('unit', 'expected "", "K", "M" or "G", got true')])
        ->and($units->read($read('{"unit": {}}'))->problems())
        ->toEqual([Problem::at('unit', 'expected "", "K", "M" or "G", got a list')])
        ->and($units->read($read('{"unit": null}'))->problems())
        ->toEqual([Problem::at('unit', 'expected "", "K", "M" or "G", got null')]);
});
