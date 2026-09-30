<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The results file a run writes, in a fresh workspace. */
$results = static fn(): string => sprintf('%s/.mutation-gate/pest/results.jsonl', realpath(Scratch::directory()));

it('keeps a directory for each process of the gate, beside the results', function () use ($results): void {
    $file = $results();

    expect(MemoryScan::directoryBeside($file))->toBe(sprintf('%s/php/%d', dirname($file), getmypid()));
});

it('writes the cap whole, clearing what an earlier process of the same id left, and removes it once done', function () use (
    $results,
): void {
    $file = $results();
    $directory = MemoryScan::directoryBeside($file);
    mkdir($directory, recursive: true);
    file_put_contents(sprintf('%s/%s', $directory, MemoryCap::STAGED), 'memory_limit=1K');
    file_put_contents(sprintf('%s/foreign.ini', $directory), 'extension=elsewhere.so');

    $scan = MemoryScan::beside($file, MemoryCap::of(64, MemoryUnit::Megabytes));
    $scanned = $scan instanceof MemoryScan ? $scan->onto(Command::of('pest'))->environment()[MemoryCap::SCAN_DIR] : $scan;
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

it('refuses a link at any level of its directory, and anything but a file in it', function (string $where) use (
    $results,
): void {
    $file = $results();
    $directory = MemoryScan::directoryBeside($file);
    $elsewhere = Scratch::directory();
    $linked = match ($where) {
        'the directory' => $directory,
        'the directory above it' => dirname($directory),
        default => sprintf('%s/inside', $directory),
    };
    mkdir(dirname($linked), recursive: true);
    $where === 'a directory in it' ? mkdir($linked) : symlink($elsewhere, $linked);

    expect(MemoryScan::beside($file, MemoryCap::standard()))
        ->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', $directory, MemoryCap::FILE))))
        ->and(glob(sprintf('%s/*', $elsewhere)))->toBe([]);
})->with(['the directory', 'the directory above it', 'a directory in it']);

it('writes nothing, removes nothing, and leaves a command as it is, where the run has no cap', function () use (
    $results,
): void {
    $file = $results();
    $scan = MemoryScan::beside($file, MemoryCap::none());
    if ($scan instanceof MemoryScan) {
        $scan->remove();
    }

    expect($scan instanceof MemoryScan ? $scan->onto(Command::of('pest')) : $scan)->toEqual(Command::of('pest'))
        ->and(is_dir(MemoryScan::directoryBeside($file)))->toBeFalse();
});
