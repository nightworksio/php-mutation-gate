<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\MatrixRecord;

it('writes the matrix of a run that records every killer, and nothing of one that records first killers', function (): void {
    expect(MatrixRecord::of(MatrixKind::Full))->toBe(['matrix' => 'full'])
        ->and(MatrixRecord::of(MatrixKind::FirstKiller))->toBe([]);
});

it('reads a record without a matrix as first killers, and one whose matrix is full as every killer', function (): void {
    expect(MatrixRecord::read(Node::decode('{"matrix": "full"}')->field('matrix')))->toBe(MatrixKind::Full)
        ->and(MatrixRecord::read(Node::decode('{}')->field('matrix')))->toBe(MatrixKind::FirstKiller);
});

it('refuses a matrix that is not full', function (string $json): void {
    MatrixRecord::read(Node::decode($json)->field('matrix'));
})->with(['{"matrix": "first-killer"}', '{"matrix": "Full"}', '{"matrix": true}', '{"matrix": null}'])
    ->throws(NotInShape::class);
