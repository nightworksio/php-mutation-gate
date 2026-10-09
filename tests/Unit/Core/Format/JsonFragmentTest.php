<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\NotGiven;

it('holds a value as a file writes it, tells an object, and compares values however they are laid out', function (): void {
    expect(JsonFragment::of("{\n    \"a\": 1\n}")->isObject())->toBeTrue()
        ->and(JsonFragment::of('[1]')->isObject())->toBeFalse()
        ->and(JsonFragment::of("{\n    \"a\": [1, 2]\n}")->equals(JsonFragment::of('{"a":[1,2]}')))->toBeTrue()
        ->and(JsonFragment::of('1')->equals(JsonFragment::of('"1"')))->toBeFalse()
        ->and(JsonFragment::encoding('src/Ü.php')->text())->toBe('"src/Ü.php"');
});

it('gives the string, number or truth it holds, and none for an object, an array or null', function (): void {
    expect([JsonFragment::of('"x"')->scalar(), JsonFragment::of('2')->scalar(), JsonFragment::of('0.5')->scalar(), JsonFragment::of('false')->scalar()])
        ->toBe(['x', 2, 0.5, false])
        ->and([JsonFragment::of('{}')->scalar(), JsonFragment::of('[]')->scalar(), JsonFragment::of('null')->scalar()])
        ->toEqual([NotGiven::value(), NotGiven::value(), NotGiven::value()]);
});
