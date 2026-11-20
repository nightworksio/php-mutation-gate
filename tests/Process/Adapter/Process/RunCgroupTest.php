<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Cgroups;
use NightWorksIO\MutationGate\Adapter\Process\RunCgroup;
use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Adapter\Runtime\SystemGauge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Processes;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Orphan;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Started;

$holds = [
    'holds:src/Adapter/Process/Holding.php',
    'holds:src/Adapter/Process/RunCgroup.php',
    'holds:src/Adapter/Process/Cgroups.php',
    'holds:src/Adapter/Process/CgroupFile.php',
];

$contained = static fn(): bool => Cgroups::toldHere(Processes::of(contain: ProcessHold::Require), inCi: Variables::of(getenv())->inCi(), meminfo: SystemGauge::MEMINFO) === true;

afterEach(function (): void {
    putenv(Cgroups::VARIABLE);
    Scratch::sweep();
});

$ended = static function (Running $running): Ran {
    do {
        Running::awaitAny(Seconds::of(1.0), $running);
        $end = $running->endedBy(0.0);
    } while ($end instanceof NotGiven);

    return $end;
};

/**
 * The cgroups of the runs under the gate's own, by name.
 *
 * @return list<string>
 */
function runCgroups(): array
{
    $runs = glob(sprintf('%s/started-*', (string) getenv(Cgroups::VARIABLE)), GLOB_ONLYDIR);

    return $runs === false ? [] : $runs;
}

it('runs a process in a cgroup of its own under the gate\'s, and removes it once the process ended', function () use ($contained, $ended): void {
    $contained();
    $ran = $ended(Started::command(ProcessCommand::of(Scratch::directory(), 'cat', '/proc/self/cgroup')));

    expect($ran->output())->toMatch(sprintf('#^0::%s/started-0\n$#', preg_quote(substr((string) getenv(Cgroups::VARIABLE), strlen(Cgroups::MOUNT)), '#')))
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);

it('makes no cgroup for a run where the gate\'s takes no setting, and leaves none behind', function (): void {
    $gate = Scratch::directory();
    putenv(sprintf('%s=%s', Cgroups::VARIABLE, $gate));

    expect(RunCgroup::made())->toEqual(NotGiven::value())
        ->and(runCgroups())->toBe([]);
})->group(...$holds);

it('kills and removes a run\'s cgroup once, and leaves it gone once removed', function () use ($contained): void {
    $contained();
    $run = RunCgroup::made();
    $made = runCgroups();

    if ($run instanceof RunCgroup) {
        $run->killed();
        $run->killed();
    }

    expect($run)->toBeInstanceOf(RunCgroup::class)
        ->and($made)->toHaveCount(1)
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);

it('holds every process a run started, however deep, and the process that joined it', function () use ($contained): void {
    $contained();
    $run = RunCgroup::made();
    $joining = $run instanceof RunCgroup ? $run->joining() : [];
    $code = '$p = proc_open([PHP_BINARY, "-r", "fread(STDIN, 1);"], [0 => STDIN], $pipes); echo "up\n"; fread(STDIN, 1); proc_close($p);';
    $process = proc_open([...$joining, PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    $pid = is_resource($process) ? proc_get_status($process)['pid'] : 0;
    $held = $run instanceof RunCgroup ? $run->held() : [];
    array_map(fclose(...), $pipes);

    if (is_resource($process)) {
        proc_close($process);
    }

    if ($run instanceof RunCgroup) {
        $run->killed();
    }

    expect($held)->toHaveCount(2)
        ->and($held)->toContain($pid)
        ->and(array_filter($held, is_int(...)))->toBe($held)
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);

it('kills, once a process ended, every process it left in its cgroup, even one that left its session', function () use ($contained, $ended): void {
    $contained();
    $directory = Scratch::directory();
    $ran = $ended(Started::command(ProcessCommand::of(
        $directory,
        'sh',
        '-c',
        str_replace('"$0" -r', 'setsid "$0" -r', Orphan::LEFT),
        PHP_BINARY,
        Orphan::CODE,
    )));

    expect($ran->succeeded())->toBeTrue()
        ->and(Orphan::isGone($directory))->toBeTrue()
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);

it('stops, with a process at its deadline, every process in its cgroup', function () use ($contained): void {
    $contained();
    $directory = Scratch::directory();
    $running = Started::command(ProcessCommand::of(
        $directory,
        'sh',
        '-c',
        sprintf('%s; sleep 20', str_replace('"$0" -r', 'setsid "$0" -r', Orphan::LEFT)),
        PHP_BINARY,
        Orphan::CODE,
    )->within(Seconds::of(30.0)));

    for ($look = 0; $look < 250 && ! Orphan::isReady($directory); $look++) {
        Running::awaitAny(Seconds::of(0.02), $running);
    }

    expect($running->stop(1.0))->toEqual(Ran::stopped('', '')->took(Seconds::of(1.0)))
        ->and(Orphan::isGone($directory))->toBeTrue()
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);

it('takes a run that goes past its memory whole, and the machine keeps the rest', function () use ($ended): void {
    Cgroups::toldHere(Processes::of(contain: ProcessHold::Require, memory: 1), inCi: Variables::of(getenv())->inCi(), meminfo: SystemGauge::MEMINFO);
    $ran = $ended(Started::command(ProcessCommand::of(
        Scratch::directory(),
        PHP_BINARY,
        '-d',
        'memory_limit=-1',
        '-r',
        '$held = []; for ($i = 0; $i < 100000; $i++) { $held[] = str_repeat("x", 1 << 20); } echo "held";',
    )));

    expect($ran->endedBySignal())->toBeTrue()
        ->and($ran->output())->not->toContain('held')
        ->and(runCgroups())->toBe([]);
})->skip(fn(): bool => ! $contained(), 'no cgroup can be had here')->group(...$holds);
