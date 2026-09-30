<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Unchecked;
use NightWorksIO\MutationGate\Core\Format\Node;

it('takes any value as written, reading nothing from it, and expects anything', function (string $config): void {
    $unchecked = Unchecked::describedAs('Taken, never read.');
    $reading = $unchecked->read(Node::config($config)->field('$schema'));

    expect([$reading->value(), $reading->problems()])->toEqual([Absent::setting(), []])
        ->and($unchecked->expected())->toBe('anything')
        ->and($unchecked->schema()->line())->toBe('{"description":"Taken, never read."}')
        ->and($unchecked->effects())->toBe([]);
})->with([
    'text' => ['{"$schema": "schema.json"}'],
    'a number' => ['{"$schema": 3}'],
    'a list' => ['{"$schema": ["a"]}'],
]);
