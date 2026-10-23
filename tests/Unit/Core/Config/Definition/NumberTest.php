<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Number;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;

$read = static fn(string $json): Node => Node::config($json)->field('n');

it('takes a number from its least to its most, both allowed', function () use ($read): void {
    $number = Number::between(1, 10);

    expect($number->read($read('{"n": 1}'))->value())->toBe(1)
        ->and($number->read($read('{"n": 10}'))->value())->toBe(10)
        ->and($number->read($read('{"n": 0.5}'))->problems())->toEqual([Problem::at('n', 'expected a number from 1 to 10, got 0.5')])
        ->and($number->schema()->line())->toBe('{"type":"number","minimum":1,"maximum":10}');
});

it('takes a number above its least, which it refuses, and at most its most', function () use ($read): void {
    $number = Number::aboveAndAtMost(0, 10);

    expect($number->read($read('{"n": 0.01}'))->value())->toBe(0.01)
        ->and($number->read($read('{"n": 10}'))->value())->toBe(10)
        ->and($number->read($read('{"n": 0}'))->problems())
        ->toEqual([Problem::at('n', 'expected a number above 0 and at most 10, got 0')])
        ->and($number->read($read('{"n": 10.01}'))->problems())
        ->toEqual([Problem::at('n', 'expected a number above 0 and at most 10, got 10.01')])
        ->and($number->schema()->line())->toBe('{"type":"number","exclusiveMinimum":0,"maximum":10}');
});

it('takes a number of at least its least, with no most', function () use ($read): void {
    $number = Number::atLeast(2);

    expect($number->read($read('{"n": 2}'))->value())->toBe(2)
        ->and($number->read($read('{"n": 1}'))->problems())->toEqual([Problem::at('n', 'expected a number of at least 2, got 1')])
        ->and($number->schema()->line())->toBe('{"type":"number","minimum":2}')
        ->and(Number::percent()->expected())->toBe('a number from 0 to 100');
});
