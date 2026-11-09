<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Split\UnmadeDirectory;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('makes a fresh directory under a name nobody can guess, that only this user may enter', function (): void {
    $one = StreamDirectory::made();
    $two = StreamDirectory::made();

    try {
        expect([is_dir($one), is_dir($two), $one === $two, fileperms($one) & 0777])
            ->toBe([true, true, false, 0700])
            ->and(basename($one))->toMatch('/^mutation-gate-split-[0-9a-f]{32}$/');
    } finally {
        StreamDirectory::removed($one);
        StreamDirectory::removed($two);
    }
});

it('refuses a path where a directory is already planted', function (): void {
    $planted = sprintf('%s/planted', Scratch::directory());
    mkdir($planted);

    expect(static fn(): string => StreamDirectory::at($planted))->toThrow(UnmadeDirectory::at($planted));
});

it('refuses a path where a link is planted, to a directory or to nothing', function (bool $dangling): void {
    $target = sprintf('%s/target', Scratch::directory());
    $link = sprintf('%s/link', Scratch::directory());

    if (! $dangling) {
        mkdir($target);
    }

    symlink($target, $link);

    expect(static fn(): string => StreamDirectory::at($link))->toThrow(UnmadeDirectory::at($link))
        ->and(is_dir($target))->toBe(! $dangling);
})->with(['to a directory' => false, 'to nothing' => true]);

it('removes the directory with everything in it, and nothing a link in it points to', function (): void {
    $directory = StreamDirectory::at(sprintf('%s/streams', Scratch::directory()));
    $kept = sprintf('%s/kept', Scratch::directory());
    mkdir($kept);
    file_put_contents(sprintf('%s/file', $kept), 'kept');
    mkdir(sprintf('%s/schemata/deeper', $directory), recursive: true);
    file_put_contents(sprintf('%s/plan-1-0.json', $directory), '{}');
    file_put_contents(sprintf('%s/schemata/deeper/schema.php', $directory), '<?php');
    symlink($kept, sprintf('%s/link', $directory));

    StreamDirectory::removed($directory);

    expect([file_exists($directory), is_file(sprintf('%s/file', $kept))])->toBe([false, true]);
});
