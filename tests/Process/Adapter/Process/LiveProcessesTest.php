<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Process/LiveProcesses.php',
    'holds:src/Core/Runner/ProcessListing.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

$linux = fn(): bool => ! is_file(sprintf('%s/self/stat', LiveProcesses::PROC)) || ! function_exists('posix_sysconf');

/**
 * A program this process started, with what it runs under it, which says `up` once all of it runs and runs until
 * its input closes.
 *
 * @param  list<string>                            $command
 * @return array{int, resource, array<int, resource>}
 */
function liveProcessesStarted(array $command): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('the program did not start');
    }

    fgets($pipes[1]);

    return [proc_get_status($process)['pid'], $process, $pipes];
}

/** @param array{int, resource, array<int, resource>} $started */
function liveProcessesEnded(array $started): void
{
    [, $process, $pipes] = $started;
    array_map(fclose(...), $pipes);
    proc_close($process);
}

it('reads from /proc the same processes, parents, groups and names as ps lists for a tree three deep', function (): void {
    $waits = 'fread(STDIN, 1);';
    $grandchild = sprintf('posix_setpgid(0, 0); $p = proc_open([PHP_BINARY, "-r", %s], [0 => STDIN], $pipes); echo "up\n"; fread(STDIN, 1); proc_close($p);', var_export($waits, return: true));
    $child = sprintf('$p = proc_open([PHP_BINARY, "-r", %s], [0 => STDIN, 1 => STDOUT], $pipes); proc_close($p);', var_export($grandchild, return: true));
    $started = liveProcessesStarted([PHP_BINARY, '-r', $child]);
    $tree = [$started[0], ...LiveProcesses::read(LiveProcesses::PROC)->descendantsOf($started[0])];
    $read = LiveProcesses::read(LiveProcesses::PROC);
    $printed = LiveProcesses::printed();
    liveProcessesEnded($started);
    $of = static fn(ProcessListing $listing): array => array_map(
        static fn(int $pid): array => [$pid, $listing->parents()[$pid] ?? null, $listing->groupOf($pid), array_column($listing->uses(), 'name', 'pid')[$pid] ?? null],
        $tree,
    );

    expect($tree)->toHaveCount(3)
        ->and($of($read))->toBe($of($printed))
        ->and($read->parents())->toMatchArray([$started[0] => (int) getmypid(), $tree[2] => $started[0], $tree[1] => $tree[2]])
        ->and($read->groupOf($tree[2]))->toBe($tree[2])
        ->and($read->groupOf($tree[1]))->toBe($tree[2]);
})->skip($linux, 'the system has no /proc')->group(...$holds);

it('reads a name that holds parentheses and spaces whole, as ps lists it', function (): void {
    $named = sprintf('%s/a) S 1 (b', Scratch::directory());
    symlink(PHP_BINARY, $named);
    $started = liveProcessesStarted([$named, '-r', 'echo "up\n"; fread(STDIN, 1);']);
    $read = LiveProcesses::read(LiveProcesses::PROC);
    $printed = LiveProcesses::printed();
    liveProcessesEnded($started);
    $names = static fn(ProcessListing $listing): string => array_column($listing->uses(), 'name', 'pid')[$started[0]] ?? '';

    expect($names($read))->toBe('a) S 1 (b')
        ->and($names($printed))->toBe('a) S 1 (b')
        ->and($read->parents()[$started[0]])->toBe((int) getmypid());
})->skip($linux, 'the system has no /proc')->group(...$holds);
