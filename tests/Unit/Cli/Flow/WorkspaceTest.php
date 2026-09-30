<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps the plan, the coverage and the results under .mutation-gate', function (): void {
    expect(Workspace::plan())->toEqual(Path::of('.mutation-gate/plan.json'))
        ->and(Workspace::coverage())->toEqual(Path::of('.mutation-gate/coverage'))
        ->and(Workspace::results())->toEqual(Path::of('.mutation-gate/results'));
});

it('hands each shard a coverage directory of its own', function (): void {
    expect(Workspace::shardCoverage(ShardId::of(3)))->toEqual(Path::of('.mutation-gate/coverage/shard-3'));
});

it('leaves each shard\'s result in the directory of results, named by its number', function (): void {
    expect(Workspace::result(Path::of('build/results'), ShardId::of(12)))->toEqual(Path::of('build/results/12.json'));
});
