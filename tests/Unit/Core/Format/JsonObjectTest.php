<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonObject;

it('writes an object from the JSON of each member, keys as text', function (): void {
    expect(JsonObject::of(['a' => '1', 'b/c' => '"é/x"', 0 => 'true']))->toBe('{"a":1,"b/c":"é/x","0":true}')
        ->and(JsonObject::of([]))->toBe('{}');
});

it('writes a value with slashes, text and whole-number floats as they are', function (): void {
    expect(JsonObject::value('a/é'))->toBe('"a/é"')
        ->and(JsonObject::value(10.0))->toBe('10.0')
        ->and(JsonObject::value(['x', 'y']))->toBe('["x","y"]');
});
