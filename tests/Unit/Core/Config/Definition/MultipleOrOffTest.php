<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\MultipleOrOff;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;

$read = static fn(string $json): Node => Node::config($json)->field('n');

it('takes a whole number of at least its least, or false', function () use ($read): void {
    $multiple = MultipleOrOff::atLeast(1);

    expect($multiple->read($read('{"n": 1}'))->value())->toBe(1)
        ->and($multiple->read($read('{"n": 4}'))->value())->toBe(4)
        ->and($multiple->read($read('{"n": false}'))->value())->toBeFalse()
        ->and($multiple->schema()->line())->toBe('{"oneOf":[{"type":"integer","minimum":1},{"const":false}]}')
        ->and($multiple->effects())->toBe([]);
});

it('refuses a number under its least, true, a fraction and text', function (string $json, string $got) use ($read): void {
    expect(MultipleOrOff::atLeast(1)->read($read($json))->problems())
        ->toEqual([Problem::at('n', sprintf('expected an integer of at least 1, or false, got %s', $got))]);
})->with([
    'nought' => ['{"n": 0}', '0'],
    'true' => ['{"n": true}', 'true'],
    'a fraction' => ['{"n": 2.5}', '2.5'],
    'text' => ['{"n": "3"}', '"3"'],
]);
