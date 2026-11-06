<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\ProofRecord;

it('reads the id of each mutant an entry records, written in full or compact, in its order', function (): void {
    expect(ProofRecord::idsIn(Node::decode('{"mutants": [{"id": "a1", "status": "killed"}, ["b2", "killed"], {"id": "c3"}]}')))
        ->toBe(['a1', 'b2', 'c3']);
});

it('refuses an entry whose compact record names no mutant', function (): void {
    expect(static fn(): array => ProofRecord::idsIn(Node::decode('{"mutants": [["a1"], []]}')))
        ->toThrow(NotInShape::class, 'a mutant\'s id');
});
