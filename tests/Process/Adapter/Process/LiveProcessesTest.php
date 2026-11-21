<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\Signal;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Listed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Process/LiveProcesses.php',
    'holds:src/Core/Runner/ProcessListing.php',
    'holds:src/Core/Runner/SparedProcesses.php',
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

$senders = [
    'by posix_kill' => [fn(): LiveProcesses => new LiveProcesses()],
    'by the kill program' => [fn(): LiveProcesses => LiveProcesses::byProgram()],
];

/** The number PHP names this signal by. */
function liveProcessesNumber(Signal $signal): int
{
    return match ($signal) {
        Signal::Freeze => SIGSTOP,
        Signal::Kill => SIGKILL,
        Signal::Terminate => SIGTERM,
    };
}

/** Waits up to five seconds, a moment at a time, until this holds. */
function liveProcessesAwait(Closure $holds): void
{
    Polling::wait(Seconds::of(5.0), $holds, static function (int $microseconds): void {
        Running::awaitAny(Seconds::of($microseconds / Seconds::MICROSECONDS));
    });
}

/**
 * A program that leads a group of its own with a child in it, both until their input closes: the leader's handles,
 * and the child's id.
 *
 * @return array{array{int, resource, array<int, resource>}, int}
 */
function liveProcessesGroup(): array
{
    $leads = 'posix_setpgid(0, 0); $p = proc_open([PHP_BINARY, "-r", "echo getmypid(), \"\n\"; fread(STDIN, 1);"], [0 => STDIN, 1 => ["pipe", "w"]], $pipes); echo trim((string) fgets($pipes[1])), "\n"; fread(STDIN, 1); proc_close($p);';
    $process = proc_open([PHP_BINARY, '-r', $leads], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('the program did not start');
    }

    $child = (int) fgets($pipes[1]);

    return [[proc_get_status($process)['pid'], $process, $pipes], $child];
}

/**
 * How a started program stands once it was signalled, waiting a while for the signal to take: stopped by which
 * signal, ended by which, or running.
 *
 * @param array{int, resource, array<int, resource>} $started
 */
function liveProcessesStanding(array $started): string
{
    $standing = 'running';
    liveProcessesAwait(static function () use ($started, &$standing): bool {
        $status = proc_get_status($started[1]);
        $standing = match (true) {
            $status['stopped'] => sprintf('stopped by %d', $status['stopsig']),
            $status['signaled'] => sprintf('ended by %d', $status['termsig']),
            ! $status['running'] => 'ended',
            default => 'running',
        };

        return $standing !== 'running';
    });

    return $standing;
}

/** @param array{int, resource, array<int, resource>} $started */
function liveProcessesKilled(array $started): void
{
    new LiveProcesses()->signalledUnspared($started[0], Signal::Kill);
    liveProcessesEnded($started);
}

it('sends a process each signal the gate sends, alike by either path', function (LiveProcesses $live, Signal $signal, string $standing): void {
    $started = liveProcessesStarted([PHP_BINARY, '-r', 'echo "up\n"; fread(STDIN, 1);']);
    $sent = $live->signalledUnspared($started[0], $signal);
    $stood = liveProcessesStanding($started);
    liveProcessesKilled($started);

    expect($sent)->toBeTrue()
        ->and($stood)->toBe(sprintf($standing, liveProcessesNumber($signal)));
})->with($senders)->with([
    'kill' => [Signal::Kill, 'ended by %d'],
    'terminate' => [Signal::Terminate, 'ended by %d'],
    'freeze' => [Signal::Freeze, 'stopped by %d'],
])->skip(fn(): bool => ! Forking::isAvailable(), 'the test names a signal by its number')->group(...$holds);

it('sends a whole group a signal by its leader\'s negated id, alike by either path', function (LiveProcesses $live): void {
    [$started, $child] = liveProcessesGroup();
    $sent = $live->signalledUnspared(-$started[0], Signal::Kill);
    $stood = liveProcessesStanding($started);
    liveProcessesAwait(static fn(): bool => ! array_key_exists($child, new LiveProcesses()->listed()->parents()));
    $left = array_key_exists($child, new LiveProcesses()->listed()->parents());
    liveProcessesEnded($started);

    expect($sent)->toBeTrue()
        ->and($stood)->toBe(sprintf('ended by %d', liveProcessesNumber(Signal::Kill)))
        ->and($left)->toBeFalse();
})->with($senders)->skip(fn(): bool => ! Forking::isAvailable(), 'the test names a signal by its number')->group(...$holds);

it('sends nothing to a process or a group that is no longer there, alike by either path', function (LiveProcesses $live): void {
    $started = liveProcessesStarted([PHP_BINARY, '-r', 'echo "up\n"; fread(STDIN, 1);']);
    liveProcessesEnded($started);

    expect($live->signalledUnspared($started[0], Signal::Kill))->toBeFalse()
        ->and($live->signalledUnspared(-$started[0], Signal::Kill))->toBeFalse();
})->with($senders)->group(...$holds);

it('reaches no process the gate spares by either path, and reaches the one it lets through', function (LiveProcesses $live): void {
    $started = liveProcessesStarted([PHP_BINARY, '-r', 'echo "up\n"; fread(STDIN, 1);']);
    $asked = [];
    $spared = SparedProcesses::listed(new Listed(
        static fn(): ProcessListing => $live->listed(),
        static function (int $target, Signal $signal) use ($live, $started, &$asked): bool {
            $asked[] = $target;

            return $target === $started[0] && $live->signalledUnspared($target, $signal);
        },
    ));
    $parent = posix_getppid();
    $refused = [
        $spared->signal((int) getmypid(), Signal::Freeze),
        $spared->signal($parent, Signal::Freeze),
        $spared->signal(1, Signal::Freeze),
        $spared->signal(0, Signal::Freeze),
        $spared->signal(-1, Signal::Freeze),
        $spared->signalGroup((int) posix_getpgid(0), Signal::Freeze),
        $spared->signalGroup((int) posix_getsid(0), Signal::Freeze),
        $spared->signalGroup((int) posix_getpgid($parent), Signal::Freeze),
    ];
    $askedWhenRefused = $asked;
    $through = $spared->signal($started[0], Signal::Kill);
    $stood = liveProcessesStanding($started);
    liveProcessesEnded($started);

    expect($refused)->toBe(array_fill(0, 8, value: false))
        ->and($askedWhenRefused)->toBe([])
        ->and($through)->toBeTrue()
        ->and($asked)->toBe([$started[0]])
        ->and($stood)->toBe(sprintf('ended by %d', liveProcessesNumber(Signal::Kill)));
})->with($senders)->skip(fn(): bool => ! Forking::isAvailable(), 'the test names a signal by its number')->group(...$holds);
