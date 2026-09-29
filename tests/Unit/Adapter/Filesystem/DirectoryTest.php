<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads a file it holds', function (): void {
    $root = Scratch::directory();
    file_put_contents(sprintf('%s/plan.json', $root), '{"format": 1}');

    expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(Contents::of('{"format": 1}'));
});

it('names a file it does not hold', function (): void {
    $root = Scratch::directory();

    expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(Missing::at(Path::of('plan.json')));
});

it('cannot judge with a directory where a file should be', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/plan.json', $root));

    expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be read.', $root)));
});

it('writes a file, creating the directories it needs, and says where', function (): void {
    $root = Scratch::directory();
    $written = Directory::at(sprintf('%s/', $root))->write(Path::of('.mutation-gate/results/1.json'), Contents::of('{"shard": 1}'));

    expect($written)->toEqual(Written::to(sprintf('%s/.mutation-gate/results/1.json', $root)))
        ->and(file_get_contents(sprintf('%s/.mutation-gate/results/1.json', $root)))->toBe('{"shard": 1}');
});

it('replaces what a file held', function (): void {
    $root = Scratch::directory();
    $directory = Directory::at($root);
    $directory->write(Path::of('baseline.json'), Contents::of('old'));

    expect($directory->write(Path::of('baseline.json'), Contents::of('new')))->toEqual(Written::to(sprintf('%s/baseline.json', $root)))
        ->and($directory->read(Path::of('baseline.json')))->toEqual(Contents::of('new'));
});

it('cannot judge writing over a directory', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/report', $root));

    expect(Directory::at($root)->write(Path::of('report'), Contents::of('x')))->toEqual(CannotJudge::because(sprintf('%s/report could not be written.', $root)));
});
