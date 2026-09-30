<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
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

it('writes the cap whole into a directory of this process, in Infection\'s own', function () use ($project): void {
    $at = $project();
    $directory = MemoryCap::directoryIn($at->own('.'));

    $scan = MemoryScan::in($at, MemoryCap::of(64, MemoryUnit::Megabytes));

    expect($scan instanceof MemoryScan ? $scan->onto(Command::php('-v'))->environment()[MemoryCap::SCAN_DIR] : $scan)
        ->toBe(MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory))
        ->and(file_get_contents(sprintf('%s/%s', $directory, MemoryCap::FILE)))->toBe("memory_limit=64M\n");
});

it('refuses to write the cap through a linked directory, and writes nothing for no cap', function () use ($project): void {
    $at = $project();
    $directory = MemoryCap::directoryIn($at->own('.'));
    mkdir(dirname($directory), recursive: true);
    symlink(Scratch::directory(), $directory);

    expect(MemoryScan::in($at, MemoryCap::standard()))
        ->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', $directory, MemoryCap::FILE))))
        ->and(MemoryScan::in($project(), MemoryCap::none()))->toBeInstanceOf(MemoryScan::class);
});
