<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\TestPartRecord;
use NightWorksIO\MutationGate\Core\Test\TestPart;

it('writes nothing of every test file, and a part with how many parts there are', function (): void {
    expect(TestPartRecord::of(TestPart::whole()))->toBe([])
        ->and(TestPartRecord::of(TestPart::of(2, 3)))->toBe(['testPart' => 2, 'testParts' => 3]);
});

it('reads back what it wrote, and a shard that names no part as judged by every test file', function (): void {
    expect(TestPartRecord::read(Node::decode('{"testPart": 2, "testParts": 3}')))->toEqual(TestPart::of(2, 3))
        ->and(TestPartRecord::read(Node::decode('{}')))->toEqual(TestPart::whole());
});

it('refuses a part there is not', function (string $json): void {
    expect(static fn(): TestPart => TestPartRecord::read(Node::decode($json)))->toThrow(NotInShape::class);
})->with([
    'past the last part' => ['{"testPart": 4, "testParts": 3}'],
    'part nought' => ['{"testPart": 0, "testParts": 3}'],
    'one part of one, which is written as none' => ['{"testPart": 1, "testParts": 1}'],
    'no count of parts' => ['{"testPart": 1}'],
]);
