<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads each entry of a list, kept as often as it is written', function (): void {
    expect(Items::of(Text::of('a name'))->read(Node::config('["a", "b", "a"]'))->value())
        ->toEqual(Listed::of('a', 'b', 'a'));
});

it('keeps each entry of a distinct list once, as its identity says', function (): void {
    $distinct = Items::distinct(Text::of('a name'), static fn(string $name): string => strtolower($name));

    expect($distinct->read(Node::config('["a", "b", "A"]'))->value())->toEqual(Listed::of('a', 'b'))
        ->and($distinct->read(Node::config('["a", 3]'))->problems())->toHaveCount(1);
});
