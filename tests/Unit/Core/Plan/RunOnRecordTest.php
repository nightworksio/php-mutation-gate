<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
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

it('writes and reads back a pull request, a merge group and a detached HEAD', function (RunOn $runOn): void {
    expect(RunOnRecord::read(Node::decode((string) json_encode(RunOnRecord::of($runOn)))))->toEqual($runOn);
})->with([
    'a pull request' => [fn(): RunOn => RunOn::at(Scope::pullRequest(7), Scope::branch('main'))],
    'a merge group' => [fn(): RunOn => RunOn::mergeGroup(Revision::ref(str_repeat('a', 40)), Scope::branch('main'))],
    'a detached HEAD' => [fn(): RunOn => RunOn::detached(CannotTell::because('The plan names no default branch.'))],
]);

it('writes a merge group\'s base alone, with no ref', function (): void {
    expect(RunOnRecord::of(RunOn::mergeGroup(Revision::ref(str_repeat('b', 40)), Scope::branch('main'))))
        ->toBe(['defaultBranch' => 'refs/heads/main', 'mergeGroup' => str_repeat('b', 40)]);
});

it('refuses a merge group that names no full commit, such as an option git would read', function (string $base): void {
    RunOnRecord::read(Node::decode((string) json_encode(['mergeGroup' => $base])));
})->throws(NotInShape::class)->with(['--output=/tmp/x', '85c3298', '']);
