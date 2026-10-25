<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;

it('reads a whole number of bytes, a single one included, as that many', function (): void {
    expect(WrittenBytes::read(Node::decode('1')))->toEqual(MemoryCap::of(1, MemoryUnit::Bytes))
        ->and(WrittenBytes::read(Node::decode('1048576')))->toEqual(MemoryCap::of(1, MemoryUnit::Megabytes));
});

it('refuses no bytes as no amount of memory', function (): void {
    WrittenBytes::read(Node::decode('0'));
})->throws(NotInShape::class, 'a number of bytes');
