<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ProcessTree;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('stops every process a program started, at any depth, and then the program', function (): void {
    $directory = (string) realpath(Scratch::directory());
    // The grandchild holds a lock for as long as it lives, says so once it does, and marks the tree unstopped if it
    // outlives its sleep. Its output reaches the program's, as each process inherits its parent's.
    $grandchild = <<<'CODE'
        $lock = fopen("alive", "c"); flock($lock, LOCK_EX); echo "ready\n"; flush(); sleep(20); touch("outlived");
        CODE;
    $child = sprintf('proc_open([PHP_BINARY, "-r", %s], [], $pipes); sleep(20);', var_export($grandchild, return: true));
    $program = new Process(
        [PHP_BINARY, '-r', sprintf('proc_open([PHP_BINARY, "-r", %s], [], $pipes); sleep(20);', var_export($child, return: true))],
        $directory,
    );
    $program->start();
    // Stopped only once the whole tree has started, however long that takes.
    $program->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'ready'));

    ProcessTree::of($program)->stop();
    // The lock is released the moment the grandchild ends, so taking it waits for exactly that.
    $alive = fopen(sprintf('%s/alive', $directory), 'c');
    $ended = $alive !== false && flock($alive, LOCK_EX);

    expect($program->isRunning())->toBeFalse()
        ->and($ended)->toBeTrue()
        ->and(is_file(sprintf('%s/outlived', $directory)))->toBeFalse();
});

it('stops a program that started nothing', function (): void {
    $program = new Process([PHP_BINARY, '-r', 'echo "ready\n"; flush(); sleep(20);']);
    $program->start();
    $program->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'ready'));

    ProcessTree::of($program)->stop();

    expect($program->isRunning())->toBeFalse();
});
