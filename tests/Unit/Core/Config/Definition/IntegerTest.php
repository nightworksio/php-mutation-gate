<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Integer;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;

$read = static fn(string $json): Node => Node::config($json)->field('n');

it('takes an integer of at least its least, with no most', function () use ($read): void {
    $integer = Integer::atLeast(1);

    expect($integer->read($read('{"n": 1}'))->value())->toBe(1)
        ->and($integer->read($read('{"n": 1000000}'))->value())->toBe(1_000_000)
        ->and($integer->read($read('{"n": 0}'))->problems())->toEqual([Problem::at('n', 'expected an integer of at least 1, got 0')])
        ->and($integer->schema()->line())->toBe('{"type":"integer","minimum":1}')
        ->and($integer->effects())->toBe([]);
});

it('takes an integer from its least to its most, both allowed', function () use ($read): void {
    $integer = Integer::between(1, 19);

    expect($integer->read($read('{"n": 1}'))->value())->toBe(1)
        ->and($integer->read($read('{"n": 19}'))->value())->toBe(19)
        ->and($integer->read($read('{"n": 20}'))->problems())->toEqual([Problem::at('n', 'expected an integer from 1 to 19, got 20')])
        ->and($integer->read($read('{"n": 0}'))->problems())->toEqual([Problem::at('n', 'expected an integer from 1 to 19, got 0')])
        ->and($integer->read($read('{"n": 9.5}'))->problems())->toEqual([Problem::at('n', 'expected an integer from 1 to 19, got 9.5')])
        ->and($integer->schema()->line())->toBe('{"type":"integer","minimum":1,"maximum":19}');
});
