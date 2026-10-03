<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MemoryScan;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static fn(): Project => Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.gate'));

it('writes the cap whole into a directory of its own for each process of the gate, showing errors on the standard output, and removes it once done', function () use (
    $project,
): void {
    $at = $project();
    $directory = $at->own(sprintf('php/%d', getmypid()));
    mkdir($directory, recursive: true);
    file_put_contents(sprintf('%s/foreign.ini', $directory), 'extension=elsewhere.so');

    $scan = MemoryScan::in($at, MemoryCap::of(64, MemoryUnit::Megabytes), new CapDirectory());
    $scanned = $scan instanceof MemoryScan ? $scan->onto(Command::php('-v'))->scanned() : $scan;
    $held = glob(sprintf('%s/*', $directory));
    $written = (string) file_get_contents(sprintf('%s/%s', $directory, MemoryCap::FILE));
    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect($scanned)->toEqual(DiskPath::of($directory))
        ->and($held)->toBe([sprintf('%s/%s', $directory, MemoryCap::FILE)])
        ->and($written)->toBe("memory_limit=64M\ndisplay_errors=stdout\n")
        ->and(is_dir($directory))->toBeFalse();
});

it('writes nothing, removes nothing, and leaves a command as it is, where the run has no cap', function () use ($project): void {
    $at = $project();
    $scan = MemoryScan::in($at, MemoryCap::none(), new CapDirectory());
    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect($scan instanceof MemoryScan ? $scan->onto(Command::php('-v')) : $scan)->toEqual(Command::php('-v'))
        ->and(is_dir($at->own(sprintf('php/%d', getmypid()))))->toBeFalse();
});

it('cannot judge a run whose cap it cannot write, where a link stands for the gate\'s directory', function () use ($project): void {
    $at = $project();
    symlink(Scratch::directory(), $at->workspace()->value());

    expect(MemoryScan::in($at, MemoryCap::standard(), new CapDirectory()))->toEqual(CannotJudge::because(sprintf(
        MemoryCap::UNWRITTEN,
        sprintf('%s/%s', $at->own(sprintf('php/%d', getmypid())), MemoryCap::FILE),
    )));
});
