<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\Signal;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Tests\Support\Listed;

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
    return SparedProcesses::listed(new LiveProcesses());
}

it('sends no signal to itself, its parent, its group or the group of a process above it, as the system lists them', function (): void {
    $sent = [];
    $spared = SparedProcesses::listed(new Listed(static fn(): ProcessListing => new LiveProcesses()->listed(), static function (int $target) use (&$sent): bool {
        $sent[] = $target;

        return true;
    }));

    expect($spared->signal((int) getmypid(), Signal::Terminate))->toBeFalse()
        ->and($spared->signal(posix_getppid(), Signal::Terminate))->toBeFalse()
        ->and($spared->signalGroup(posix_getpgrp(), Signal::Terminate))->toBeFalse()
        ->and($spared->signalGroup(posix_getppid(), Signal::Terminate))->toBeFalse()
        ->and($spared->signalGroup((int) getmypid(), Signal::Terminate))->toBeFalse()
        ->and($sent)->toBe([]);
})->group(...$holds);

it('sends a signal to a process it started, and to the group that process leads', function (bool $leads): void {
    [$pid, $process, $pipes] = sparedStarted($leads);
    $sent = $leads ? sparedListed()->signalGroup($pid, Signal::Kill) : sparedListed()->signal($pid, Signal::Kill);

    do {
        $status = proc_get_status($process);
    } while ($status['running']);

    array_map(fclose(...), $pipes);
    proc_close($process);

    expect($sent)->toBeTrue()
        ->and($status['signaled'])->toBeTrue()
        ->and($status['termsig'])->toBe(SIGKILL);
})->with(['a process' => [false], 'a group' => [true]])->group(...$holds);
