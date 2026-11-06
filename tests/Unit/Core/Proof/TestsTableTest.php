<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\TestsTable;

it('holds each test once under its class, the classes in the order first named, and indexes them in that order', function (): void {
    $table = TestsTable::of('B::one', 'A::one', 'B::two', 'B::one', 'A::x::y', 'Bare', '::lead', '12::3');

    expect(JsonText::object($table->written()))->toBe('{"B":["one","two"],"A":["one","x::y"],"":["Bare","::lead"],"12":["3"]}')
        ->and($table->indices())->toBe(['B::one' => 0, 'B::two' => 1, 'A::one' => 2, 'A::x::y' => 3, 'Bare' => 4, '::lead' => 5, '12::3' => 6]);
});

it('reads back the ids it wrote, each at its index', function (): void {
    $table = TestsTable::of('B::one', 'A::one', 'B::two', 'Bare', '::lead', '12::3');

    expect(TestsTable::read(Node::decode(JsonText::object($table->written()))))
        ->toBe(['B::one', 'B::two', 'A::one', 'Bare', '::lead', '12::3']);
});

it('reads an earlier format\'s list of ids as it is, and none where an id is not text', function (string $json, array $ids): void {
    expect(TestsTable::read(Node::decode($json)))->toBe($ids);
})->with([
    'a list' => ['["A::one", "B::two"]', ['A::one', 'B::two']],
    'an empty map' => ['{}', []],
    'a list with a number' => ['["A::one", 3]', []],
    'a class whose tests are not a list' => ['{"A": "one"}', []],
    'a class with a test that is not text' => ['{"A": ["one", 2]}', []],
    'nothing' => ['7', []],
]);
