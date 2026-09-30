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
