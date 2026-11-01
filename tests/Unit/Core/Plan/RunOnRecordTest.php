<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\RunOnRecord;
use NightWorksIO\MutationGate\Core\Proof\Scope;

it('writes the run\'s ref, its default branch and the branch it merges into, and reads them back', function (): void {
    $run = RunOn::at(Scope::pullRequest(12), Scope::branch('main'))->withTarget(Scope::branch('release/2.x'));

    expect(RunOnRecord::of($run))->toBe([
        'ref' => 'refs/pull/12',
        'defaultBranch' => 'refs/heads/main',
        'target' => 'refs/heads/release/2.x',
    ])
        ->and(RunOnRecord::read(Node::decode((string) json_encode(RunOnRecord::of($run)))))->toEqual($run);
});

it('writes nothing it cannot name, and reads a run with none as detached, its default branch unknown', function (): void {
    $run = RunOn::detached(CannotTell::because('The plan names no default branch.'));

    expect(RunOnRecord::of($run))->toBe([])
        ->and(RunOnRecord::read(Node::decode('{}')))->toEqual($run);
});

it('refuses a field that names no scope', function (string $file): void {
    expect(static fn(): RunOn => RunOnRecord::read(Node::decode($file)))->toThrow(NotInShape::class);
})->with([
    'a ref' => ['{"ref": "refs/tags/v1"}'],
    'a default branch' => ['{"defaultBranch": "main"}'],
    'a target' => ['{"ref": "refs/pull/12", "target": "release"}'],
]);
