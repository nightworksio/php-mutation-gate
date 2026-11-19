<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Cgroups;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Processes;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(Cgroups::VARIABLE);
    Scratch::sweep();
});

/**
 * A mount that holds the cgroup a process runs in, `held/gate`, its parent handing down these controllers, the
 * gate's own cgroup beside it with the files Linux would give it, and the files that say which cgroup the process
 * runs in and how much memory the machine has.
 *
 * @return array{string, string, string, string} the mount, the process's cgroup file, the meminfo file, the gate's cgroup
 */
function cgroupMount(string $controllers = 'cpu memory pids', string $own = "0::/held/gate\n", bool $given = true): array
{
    $root = Scratch::directory();
    $mount = sprintf('%s/cgroup', $root);
    $gate = sprintf('%s/held/mutation-gate-%d', $mount, getmypid());
    mkdir(sprintf('%s/held/gate', $mount), recursive: true);
    file_put_contents(sprintf('%s/held/cgroup.procs', $mount), '');
    file_put_contents(sprintf('%s/held/cgroup.subtree_control', $mount), sprintf("%s\n", $controllers));

    foreach ($given ? ['cgroup.subtree_control', 'memory.max', 'memory.swap.max', 'pids.max', 'cgroup.kill'] : [] as $file) {
        if (! is_dir($gate)) {
            mkdir($gate);
        }

        file_put_contents(sprintf('%s/%s', $gate, $file), '');
    }

    file_put_contents(sprintf('%s/self', $root), $own);
    file_put_contents(sprintf('%s/meminfo', $root), "MemTotal:       16000000 kB\nMemFree:         1000 kB\n");

    return [$mount, sprintf('%s/self', $root), sprintf('%s/meminfo', $root), $gate];
}

$never = static fn(string $mount): bool => $mount === '';

it('makes the gate\'s cgroup beside the one it runs in, capped at its share of the memory with no swap and at its processes, and tells it', function () use ($never): void {
    [$mount, $own, $meminfo, $gate] = cgroupMount();

    expect(Cgroups::told(Processes::of(memory: 50, pids: 300), $mount, $own, $meminfo, $never))->toBeTrue()
        ->and(getenv(Cgroups::VARIABLE))->toBe($gate)
        ->and(file_get_contents(sprintf('%s/cgroup.subtree_control', $gate)))->toBe('+memory +pids')
        ->and(file_get_contents(sprintf('%s/memory.max', $gate)))->toBe('8192000000')
        ->and(file_get_contents(sprintf('%s/memory.swap.max', $gate)))->toBe('0')
        ->and(file_get_contents(sprintf('%s/pids.max', $gate)))->toBe('300');
});

it('caps at 85% of the memory and 8192 processes where no layer says', function () use ($never): void {
    [$mount, $own, $meminfo, $gate] = cgroupMount();
    Cgroups::told(Processes::standard(), $mount, $own, $meminfo, $never);

    expect(file_get_contents(sprintf('%s/memory.max', $gate)))->toBe('13926400000')
        ->and(file_get_contents(sprintf('%s/pids.max', $gate)))->toBe('8192');
});

it('makes none under processes.contain off, and tells none', function () use ($never): void {
    [$mount, $own, $meminfo, $gate] = cgroupMount();
    putenv(sprintf('%s=elsewhere', Cgroups::VARIABLE));

    expect(Cgroups::told(Processes::of(contain: ProcessHold::Off), $mount, $own, $meminfo, $never))->toBeTrue()
        ->and(getenv(Cgroups::VARIABLE))->toBeFalse()
        ->and(file_get_contents(sprintf('%s/memory.max', $gate)))->toBe('');
});

it('runs uncontained under auto where it can have no cgroup, and refuses under require, saying why', function (string $controllers, string $own, string $why) use ($never): void {
    [$mount, $self, $meminfo] = cgroupMount($controllers, $own);

    expect(Cgroups::told(Processes::standard(), $mount, $self, $meminfo, $never))->toBeTrue()
        ->and(getenv(Cgroups::VARIABLE))->toBeFalse()
        ->and(Cgroups::told(Processes::of(contain: ProcessHold::Require), $mount, $self, $meminfo, $never))->toEqual(CannotJudge::because(sprintf(<<<'SAID'
            processes.contain is require, but %s.
            Hand the gate a cgroup it may write with the memory and pids controllers, or run it in CI where sudo needs
            no password, or set processes.contain to auto or off.
            SAID, str_replace('%mount%', $mount, $why))));
})->with([
    'no cgroup v2' => ['cpu memory pids', "1:name=systemd:/held/gate\n", 'this system has no cgroup v2 at %mount%'],
    'no memory controller handed down' => ['cpu pids', "0::/held/gate\n", '%mount%/held hands down no memory and pids controllers'],
    'no pids controller handed down' => ['memory', "0::/held/gate\n", '%mount%/held hands down no memory and pids controllers'],
]);

it('says it may not write a parent it may not write', function () use ($never): void {
    [$mount, $own, $meminfo] = cgroupMount();
    chmod(sprintf('%s/held/cgroup.procs', $mount), 0o444);

    $why = Cgroups::told(Processes::of(contain: ProcessHold::Require), $mount, $own, $meminfo, $never);

    expect($why instanceof CannotJudge ? $why->why() : '')->toContain(sprintf('the gate may not write %s/held', $mount));
})->skip(fn(): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes every file');

it('says its cgroup could not be made and capped where the machine\'s memory is unknown', function () use ($never): void {
    [$mount, $own, , $gate] = cgroupMount();
    $why = Cgroups::told(Processes::of(contain: ProcessHold::Require), $mount, $own, sprintf('%s/none', $mount), $never);

    expect($why instanceof CannotJudge ? $why->why() : '')->toContain(sprintf('%s could not be made and capped', $gate))
        ->and(file_get_contents(sprintf('%s/memory.max', $gate)))->toBe('');
});

it('removes the cgroup it made where Linux refuses a cap, and says so', function () use ($never): void {
    [$mount, $own, $meminfo, $gate] = cgroupMount(given: false);
    $why = Cgroups::told(Processes::of(contain: ProcessHold::Require), $mount, $own, $meminfo, $never);

    expect($why instanceof CannotJudge ? $why->why() : '')->toContain(sprintf('%s could not be made and capped', $gate))
        ->and(is_dir($gate))->toBeFalse();
});

it('makes it once the process was moved into a cgroup it owns, where none was', function (): void {
    [$mount, $own, $meminfo, $gate] = cgroupMount(own: "0::/\n");
    $moved = static function (string $at) use ($mount, $own): bool {
        file_put_contents($own, "0::/held/gate\n");

        return $at === $mount;
    };

    expect(Cgroups::told(Processes::standard(), $mount, $own, $meminfo, $moved))->toBeTrue()
        ->and(getenv(Cgroups::VARIABLE))->toBe($gate);
});
