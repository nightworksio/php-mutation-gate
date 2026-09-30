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

it('cannot judge a file it cannot read', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'plan.json', '{}');
    chmod(sprintf('%s/plan.json', $root), 0o000);
    set_error_handler(static fn(): bool => true);
    $read = Directory::at($root)->read(Path::of('plan.json'));
    restore_error_handler();

    expect($read)->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be read.', $root)));
});

it('cannot judge a file it could not write', function (): void {
    $root = Scratch::directory();
    chmod($root, 0o500);
    set_error_handler(static fn(): bool => true);
    $written = Directory::at($root)->write(Path::of('plan.json'), Contents::of('{}'));
    restore_error_handler();
    chmod($root, 0o700);

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be written.', $root)));
});

it('cannot judge a file whose directory it could not make', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'report', 'a file where a directory should be');
    set_error_handler(static fn(): bool => true);
    $written = Directory::at($root)->write(Path::of('report/index.html'), Contents::of('<html>'));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/report/index.html could not be written.', $root)));
});

it('streams a file piece by piece, creating the directories it needs, and replaces what it held', function (): void {
    $root = Scratch::directory();
    $pieces = static function (): Generator {
        yield "mutant,test\r\n";
        yield "3f9a1c2b7d04,MoneyTest::fits\r\n";
    };
    Scratch::write($root, 'build/kill-matrix.csv', 'old');

    expect(Directory::at($root)->stream(Path::of('build/kill-matrix.csv'), $pieces()))->toEqual(Written::to(sprintf('%s/build/kill-matrix.csv', $root)))
        ->and(file_get_contents(sprintf('%s/build/kill-matrix.csv', $root)))->toBe("mutant,test\r\n3f9a1c2b7d04,MoneyTest::fits\r\n")
        ->and(Directory::at($root)->stream(Path::of('fresh/empty.csv'), []))->toEqual(Written::to(sprintf('%s/fresh/empty.csv', $root)))
        ->and(file_get_contents(sprintf('%s/fresh/empty.csv', $root)))->toBe('');
});

it('cannot judge streaming over a directory, into one it cannot write, or under a file', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/report', $root));
    mkdir(sprintf('%s/locked', $root));
    Scratch::write($root, 'file', 'a file where a directory should be');
    chmod(sprintf('%s/locked', $root), 0o500);
    set_error_handler(static fn(): bool => true);
    $overDirectory = Directory::at($root)->stream(Path::of('report'), ['x']);
    $locked = Directory::at($root)->stream(Path::of('locked/matrix.csv'), ['x']);
    $underFile = Directory::at($root)->stream(Path::of('file/matrix.csv'), ['x']);
    restore_error_handler();
    chmod(sprintf('%s/locked', $root), 0o700);

    expect($overDirectory)->toEqual(CannotJudge::because(sprintf('%s/report could not be written.', $root)))
        ->and($locked)->toEqual(CannotJudge::because(sprintf('%s/locked/matrix.csv could not be written.', $root)))
        ->and($underFile)->toEqual(CannotJudge::because(sprintf('%s/file/matrix.csv could not be written.', $root)));
});

it('cannot judge a stream whose pieces could not all be written', function (): void {
    set_error_handler(static fn(): bool => true);
    $written = Directory::at('/dev')->stream(Path::of('full'), ['mutant,test', "\r\n"]);
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because('/dev/full could not be written.'));
})->skip(! file_exists('/dev/full'), 'Only a system with /dev/full refuses every write.');
