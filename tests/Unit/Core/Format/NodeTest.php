<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

$file = static fn(): Node => Node::decode('{"name": "x", "count": 3, "share": 1.5, "list": ["a", "b"], "map": {"k": "v", "7": "w"}}');

it('reads text, whole numbers and numbers where they are', function () use ($file): void {
    expect($file()->field('name')->text())->toBe('x')
        ->and($file()->field('count')->integer())->toBe(3)
        ->and($file()->field('count')->number())->toBe(3.0)
        ->and($file()->field('share')->number())->toBe(1.5);
});

it('names each place by the path to it', function () use ($file): void {
    expect($file()->at())->toBe('the file')
        ->and($file()->field('map')->field('k')->at())->toBe('the file.map.k');
});

it('reads a list as its items, each named by its position', function () use ($file): void {
    $items = $file()->field('list')->items();

    expect(array_map(static fn(Node $item): string => $item->text(), $items))->toBe(['a', 'b'])
        ->and(array_map(static fn(Node $item): string => $item->at(), $items))->toBe(['the file.list[0]', 'the file.list[1]']);
});

it('reads a map as its entries by key, a numbered key as text', function () use ($file): void {
    $entries = $file()->field('map')->entries();

    expect(array_map(static fn(Node $entry): string => $entry->text(), $entries))->toBe(['k' => 'v', '7' => 'w'])
        ->and(array_map(static fn(Node $entry): string => $entry->at(), $entries))->toBe(['k' => 'the file.map.k', '7' => 'the file.map.7'])
        ->and(array_map(static fn(Node $entry): bool => $entry->isPresent(), $entries))->toBe(['k' => true, '7' => true]);
});

it('knows a place it holds from one it does not', function () use ($file): void {
    expect($file()->isPresent())->toBeTrue()
        ->and($file()->field('name')->isPresent())->toBeTrue()
        ->and($file()->field('absent')->isPresent())->toBeFalse()
        ->and($file()->field('name')->field('under text')->isPresent())->toBeFalse()
        ->and($file()->field('list')->items()[0]->isPresent())->toBeTrue();
});

it('refuses a place that holds something else, saying where and what it should hold', function () use ($file): void {
    expect(fn(): string => $file()->field('count')->text())->toThrow(NotInShape::at('the file.count', 'text'))
        ->and(fn(): int => $file()->field('share')->integer())->toThrow(NotInShape::at('the file.share', 'a whole number'))
        ->and(fn(): float => $file()->field('name')->number())->toThrow(NotInShape::at('the file.name', 'a number'))
        ->and(fn(): array => $file()->field('map')->items())->toThrow(NotInShape::at('the file.map', 'a list'))
        ->and(fn(): array => $file()->field('name')->items())->toThrow(NotInShape::at('the file.name', 'a list'))
        ->and(fn(): array => $file()->field('name')->entries())->toThrow(NotInShape::at('the file.name', 'a map'))
        ->and(fn(): int => $file()->field('list')->items()[0]->integer())->toThrow(NotInShape::at('the file.list[0]', 'a whole number'))
        ->and(fn(): int => $file()->field('map')->entries()['k']->integer())->toThrow(NotInShape::at('the file.map.k', 'a whole number'));
});

it('refuses a place that holds nothing as missing', function () use ($file): void {
    expect(fn(): string => $file()->field('absent')->text())->toThrow(NotInShape::missing('the file.absent'))
        ->and(fn(): array => $file()->field('absent')->entries())->toThrow(NotInShape::missing('the file.absent'));
});

it('reads text that is not JSON as a top that holds nothing readable', function (): void {
    expect(fn(): array => Node::decode('{not json')->entries())->toThrow(NotInShape::at('the file', 'a map'))
        ->and(Node::decode('{not json')->field('format')->isPresent())->toBeFalse();
});

it('reads an empty map as an empty list or no entries', function (): void {
    expect(Node::decode('{}')->entries())->toBe([])
        ->and(Node::decode('[]')->items())->toBe([]);
});

it('writes what a place holds back out as JSON, and refuses a place that holds nothing', function () use ($file): void {
    expect($file()->field('map')->json())->toBe('{"k":"v","7":"w"}')
        ->and($file()->field('list')->json())->toBe('["a","b"]')
        ->and(Node::decode('{"path": "a/é", "share": 2.0, "empty": {}}')->json())->toBe('{"path":"a/é","share":2.0,"empty":[]}')
        ->and(static fn(): string => $file()->field('absent')->json())
        ->toThrow(NotInShape::class, 'the file.absent is missing.');
});

it('reads true or false where it is, and refuses anything else', function (): void {
    $node = Node::decode('{"on": true, "off": false, "word": "yes"}');

    expect($node->field('on')->boolean())->toBeTrue()
        ->and($node->field('off')->boolean())->toBeFalse()
        ->and(fn(): bool => $node->field('word')->boolean())->toThrow(NotInShape::at('the file.word', 'true or false'));
});
