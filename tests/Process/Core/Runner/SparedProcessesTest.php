<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;

$holds = [
    'holds:src/Core/Runner/SparedProcesses.php',
];

/**
 * A process this one started, which leads a group of its own where asked, and runs until its input closes.
 *
 * @return array{int, resource, array<int, resource>}
 */
function sparedStarted(bool $leads): array
{
    $code = sprintf('%s echo "up\n"; fread(STDIN, 1);', $leads ? 'posix_setpgid(0, 0);' : '');
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('the process did not start');
    }

    fgets($pipes[1]);

    return [proc_get_status($process)['pid'], $process, $pipes];
}

function sparedListed(): SparedProcesses
{
    return SparedProcesses::listed(static function (array $command): string {
        $printed = [];
        exec(implode(' ', array_map(escapeshellarg(...), $command)), $printed);

        return implode("\n", $printed);
    });
}

it('sends no signal to itself, its parent, its group or the group of a process above it', function (): void {
    $spared = sparedListed();

    expect($spared->signal((int) getmypid(), 0))->toBeFalse()
        ->and($spared->signal(posix_getppid(), 0))->toBeFalse()
        ->and($spared->signalGroup(posix_getpgrp(), 0))->toBeFalse()
        ->and($spared->signalGroup(posix_getppid(), 0))->toBeFalse()
        ->and($spared->signalGroup((int) getmypid(), 0))->toBeFalse();
})->group(...$holds);

it('sends a signal to a process it started, and to the group that process leads', function (bool $leads): void {
    [$pid, $process, $pipes] = sparedStarted($leads);
    $sent = $leads ? sparedListed()->signalGroup($pid, SIGKILL) : sparedListed()->signal($pid, SIGKILL);

    do {
        $status = proc_get_status($process);
    } while ($status['running']);

    array_map(fclose(...), $pipes);
    proc_close($process);

    expect($sent)->toBeTrue()
        ->and($status['signaled'])->toBeTrue()
        ->and($status['termsig'])->toBe(SIGKILL);
})->with(['a process' => [false], 'a group' => [true]])->group(...$holds);
