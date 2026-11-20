<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\ChildCount;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\StreamChildren;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads a count of at least one, or "cores"', function (): void {
    expect(new ChildCount()->read(Node::config('3'))->value())->toBe(3)
        ->and(new ChildCount()->read(Node::config('"cores"'))->value())->toBe(StreamChildren::Cores);
});

it('refuses what is neither a count of at least one nor "cores", saying it wants either', function (string $written): void {
    expect(new ChildCount()->read(Node::config($written))->problems())->toEqual([
        Problem::at('', sprintf('expected an integer of at least 1, or "cores", got %s', $written)),
    ]);
})->with([
    'none' => ['0'],
    'another word' => ['"many"'],
]);
