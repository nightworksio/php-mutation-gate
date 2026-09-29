<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

it('writes one spelling for one value, sorting the keys of every map at every depth', function (): void {
    expect(Json::canonical(['b' => ['d' => 1, 'c' => [3, 2, ['f' => 1, 'e' => 2]]], 'a' => 'x/y', 'é' => 1.0]))
        ->toBe('{"a":"x/y","b":{"c":[3,2,{"e":2,"f":1}],"d":1},"é":1.0}');
});

it('keeps a long list in its order', function (): void {
    expect(Json::canonical(range(0, 11)))->toBe('[0,1,2,3,4,5,6,7,8,9,10,11]');
});

it('writes an empty map as an object and a list as a list', function (): void {
    expect(Json::encode(Json::object([])))->toBe('{}')
        ->and(Json::encode(Json::object(['a' => 1])))->toBe('{"a":1}')
        ->and(Json::pretty(['a' => []]))->toBe("{\n    \"a\": []\n}");
});

it('tells a JSON object from a list, an empty one being both', function (mixed $value, bool $isMap): void {
    expect(Json::isMap($value))->toBe($isMap);
})->with([
    'an object' => [['a' => 1], true],
    'an empty object' => [[], true],
    'a list' => [[1], false],
    'a string' => ['a', false],
]);
