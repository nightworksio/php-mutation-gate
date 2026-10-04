<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads what a place holds where it holds what is asked for', function (): void {
    $node = Node::decode('{"text": "a", "list": [1, 2], "map": {"k": 1}, "yes": true, "no": false}');

    expect(Lenient::text($node->field('text')))->toBe('a')
        ->and(Lenient::boolean($node->field('yes'), otherwise: false))->toBeTrue()
        ->and(Lenient::boolean($node->field('no'), otherwise: true))->toBeFalse()
        ->and(array_map(static fn(Node $item): string => $item->json(), Lenient::items($node->field('list'))))->toBe(['1', '2'])
        ->and(array_keys(Lenient::entries($node->field('map'))))->toBe(['k']);
});

it('reads a place that holds something else, or nothing, as holding none of it', function (): void {
    $node = Node::decode('{"text": 1, "list": {"k": 1}, "map": "m"}');

    expect(Lenient::text($node->field('text')))->toBe('')
        ->and(Lenient::text($node->field('missing')))->toBe('')
        ->and(Lenient::items($node->field('list')))->toBe([])
        ->and(Lenient::entries($node->field('map')))->toBe([])
        ->and(Lenient::entries($node->field('missing')))->toBe([])
        ->and(Lenient::boolean($node->field('text'), otherwise: true))->toBeTrue()
        ->and(Lenient::boolean($node->field('missing'), otherwise: false))->toBeFalse();
});

it('reads a whole number where a place holds one, and 0 where it holds something else or nothing', function (): void {
    $node = Node::decode('{"count": 3, "text": "3", "rate": 1.5}');

    expect(Lenient::integer($node->field('count')))->toBe(3)
        ->and(Lenient::integer($node->field('text')))->toBe(0)
        ->and(Lenient::integer($node->field('rate')))->toBe(0)
        ->and(Lenient::integer($node->field('missing')))->toBe(0);
});

it('tells a place that holds members, a map or a list, from one that holds a single value or nothing', function (): void {
    $node = Node::decode('{"map": {"k": 1}, "list": [1], "empty": {}, "text": "a", "none": null}');

    expect(Lenient::holdsMembers($node->field('map')))->toBeTrue()
        ->and(Lenient::holdsMembers($node->field('list')))->toBeTrue()
        ->and(Lenient::holdsMembers($node->field('empty')))->toBeTrue()
        ->and(Lenient::holdsMembers($node->field('text')))->toBeFalse()
        ->and(Lenient::holdsMembers($node->field('none')))->toBeFalse()
        ->and(Lenient::holdsMembers($node->field('missing')))->toBeFalse();
});

it('writes what a place holds back out as JSON, and a place that holds nothing as null', function (): void {
    $node = Node::decode('{"number": 1.5, "list": [true, "a/b"]}');

    expect(Lenient::json($node->field('number')))->toBe('1.5')
        ->and(Lenient::json($node->field('list')))->toBe('[true,"a/b"]')
        ->and(Lenient::json($node->field('missing')))->toBe('null');
});
