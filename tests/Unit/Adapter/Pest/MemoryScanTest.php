<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project, and the results file a run of it writes. */
$project = static function (): array {
    $root = (string) realpath(Scratch::directory());

    return [
        Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor')),
        sprintf('%s/.mutation-gate/pest/results.jsonl', $root),
    ];
};

it('writes the cap whole into a directory of this process, replacing what a run of it staged before', function () use (
    $project,
): void {
    [$at, $results] = $project();
    $directory = MemoryCap::directoryIn(dirname($results));
    mkdir($directory, recursive: true);
    file_put_contents(MemoryCap::stagedIn($directory), 'memory_limit=1K');

    $scan = MemoryScan::beside($at, $results, MemoryCap::of(64, MemoryUnit::Megabytes));

    expect($scan instanceof MemoryScan ? $scan->onto(Command::of('pest'))->environment()[MemoryCap::SCAN_DIR] : $scan)
        ->toBe(MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory))
        ->and(file_get_contents(sprintf('%s/%s', $directory, MemoryCap::FILE)))->toBe("memory_limit=64M\n")
        ->and(is_file(MemoryCap::stagedIn($directory)))->toBeFalse();
});

it('refuses to write the cap through a link, where its directory or what it stages should be', function (
    string $linked,
) use ($project): void {
    [$at, $results] = $project();
    $directory = MemoryCap::directoryIn(dirname($results));
    $elsewhere = Scratch::directory();
    mkdir(dirname($linked === 'directory' ? $directory : MemoryCap::stagedIn($directory)), recursive: true);
    symlink($elsewhere, $linked === 'directory' ? $directory : MemoryCap::stagedIn($directory));

    expect(MemoryScan::beside($at, $results, MemoryCap::standard()))
        ->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', $directory, MemoryCap::FILE))))
        ->and(glob(sprintf('%s/*', $elsewhere)))->toBe([]);
})->with(['directory', 'staged']);

it('writes nothing, and leaves a command as it is, where the run has no cap', function () use ($project): void {
    [$at, $results] = $project();
    $scan = MemoryScan::beside($at, $results, MemoryCap::none());

    expect($scan instanceof MemoryScan ? $scan->onto(Command::of('pest')) : $scan)->toEqual(Command::of('pest'))
        ->and(is_dir(MemoryCap::directoryIn(dirname($results))))->toBeFalse();
});
