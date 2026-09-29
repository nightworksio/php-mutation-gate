<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\NotInShape;

it('says where a place holds the wrong thing, and what it should hold', function (): void {
    expect(NotInShape::at('the file.shards[2].id', 'a shard number')->getMessage())
        ->toBe('the file.shards[2].id is not a shard number.');
});

it('says where a place holds nothing', function (): void {
    expect(NotInShape::missing('the file.commit')->getMessage())->toBe('the file.commit is missing.');
});
