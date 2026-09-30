<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\OpenObject;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

it('keeps an object of any keys as it is written, and an empty one as an object', function (string $written): void {
    $read = OpenObject::any()->read(Node::config($written));

    expect($read->problems())->toBe([])
        ->and($read->value())->toEqual($written === '[]' ? Json::object() : Json::parse($written));
})->with(['keys' => ['{"agents": {"queue": "php"}, "7": [1]}'], 'empty' => ['[]']]);

it('refuses anything but an object', function (): void {
    expect(OpenObject::any()->read(Node::config('"make"'))->problems())
        ->toEqual([Problem::at('', 'expected an object, got "make"')]);
});

it('says it expects an object, writes it as one and has no effect of its own', function (): void {
    expect(OpenObject::any()->expected())->toBe('an object')
        ->and(OpenObject::any()->schema())->toEqual(Json::parse('{"type": "object"}'))
        ->and(OpenObject::any()->effects())->toBe([]);
});
