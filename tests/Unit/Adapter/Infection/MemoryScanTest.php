<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static fn(): Project => Project::at(
    Root::of((string) realpath(Scratch::directory())),
    Paths::of(Path::of('tests')),
    Path::of('.gate'),
);

it('keeps a directory for each process of the gate, in Infection\'s own', function () use ($project): void {
    $at = $project();

    expect(MemoryScan::directoryIn($at))->toBe($at->own(sprintf('php/%d', getmypid())));
});

it('writes the cap whole, clearing what an earlier process of the same id left, and removes it once done', function () use (
    $project,
): void {
    $at = $project();
    $directory = MemoryScan::directoryIn($at);
    mkdir($directory, recursive: true);
    file_put_contents(sprintf('%s/%s', $directory, MemoryCap::STAGED), 'memory_limit=1K');
    file_put_contents(sprintf('%s/foreign.ini', $directory), 'extension=elsewhere.so');

    $scan = MemoryScan::in($at, MemoryCap::of(64, MemoryUnit::Megabytes), new CapDirectory());
    $scanned = $scan instanceof MemoryScan ? $scan->onto(Command::php('-v'))->environment()[MemoryCap::SCAN_DIR] : $scan;
    $held = glob(sprintf('%s/*', $directory));
    $written = (string) file_get_contents(sprintf('%s/%s', $directory, MemoryCap::FILE));
    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect($scanned)->toBe(MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory))
        ->and($held)->toBe([sprintf('%s/%s', $directory, MemoryCap::FILE)])
        ->and($written)->toBe("memory_limit=64M\n")
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a link at any level from the gate\'s directory down to its own, and anything but a file in it', function (string $where) use (
    $project,
): void {
    $at = $project();
    $directory = MemoryScan::directoryIn($at);
    $elsewhere = Scratch::directory();
    $linked = match ($where) {
        'the directory' => $directory,
        'the directory above it' => dirname($directory),
        'the gate\'s directory' => $at->workspace(),
        default => sprintf('%s/inside', $directory),
    };
    if (! is_dir(dirname($linked))) {
        mkdir(dirname($linked), recursive: true);
    }
    $where === 'a directory in it' ? mkdir($linked) : symlink($elsewhere, $linked);

    expect(MemoryScan::in($at, MemoryCap::standard(), new CapDirectory()))
        ->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', $directory, MemoryCap::FILE))))
        ->and(glob(sprintf('%s/*', $elsewhere)))->toBe([]);
})->with(['the directory', 'the directory above it', 'the gate\'s directory', 'a directory in it']);

it('writes nothing, removes nothing, and leaves a command as it is, where the run has no cap', function () use (
    $project,
): void {
    $at = $project();
    $scan = MemoryScan::in($at, MemoryCap::none(), new CapDirectory());
    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect($scan instanceof MemoryScan ? $scan->onto(Command::php('-v')) : $scan)->toEqual(Command::php('-v'))
        ->and(is_dir(MemoryScan::directoryIn($at)))->toBeFalse();
});

it('leaves the cap\'s directory, quietly, where something made a directory in it during the run', function () use (
    $project,
): void {
    $at = $project();
    $scan = MemoryScan::in($at, MemoryCap::standard(), new CapDirectory());
    $directory = MemoryScan::directoryIn($at);
    mkdir(sprintf('%s/made', $directory));

    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect(glob(sprintf('%s/*', $directory)))->toBe([sprintf('%s/made', $directory)])
        ->and(MemoryScan::in($at, MemoryCap::standard(), new CapDirectory()))->toBeInstanceOf(CannotJudge::class);
});
