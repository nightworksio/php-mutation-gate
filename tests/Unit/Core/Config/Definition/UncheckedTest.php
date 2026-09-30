<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Unchecked;

it('takes any value as written, and expects anything', function (mixed $value): void {
    $unchecked = Unchecked::describedAs('Kept, never read.');
    $reading = $unchecked->read($value, '$schema');

    expect([$reading->value(), $reading->shown(), $reading->problems()])->toBe([$value, $value, []])
        ->and($unchecked->expected())->toBe('anything')
        ->and($unchecked->schema())->toBe(['description' => 'Kept, never read.'])
        ->and($unchecked->effects())->toBe([]);
})->with([
    'text' => ['schema.json'],
    'a number' => [3],
    'a list' => [['a']],
]);
