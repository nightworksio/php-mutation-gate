<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\CgroupDelegation;

it('makes a cgroup under the mount, gives it to the gate\'s user, hands the controllers down and moves the gate into it', function (): void {
    $ran = [];

    $moved = CgroupDelegation::moved('/sys/fs/cgroup', static function (array $command) use (&$ran): bool {
        $ran[] = $command;

        return true;
    });

    expect($moved)->toBeTrue()
        ->and($ran)->toBe([
            ['sudo', '-n', 'mkdir', '-p', sprintf('/sys/fs/cgroup/mutation-gate/gate-%d', getmypid())],
            ['sudo', '-n', 'chown', '-R', sprintf('%d:%d', posix_geteuid(), posix_getegid()), '/sys/fs/cgroup/mutation-gate'],
            ['sudo', '-n', 'sh', '-c', 'echo "$1" > "$0"', '/sys/fs/cgroup/mutation-gate/cgroup.subtree_control', '+memory +pids'],
            ['sudo', '-n', 'sh', '-c', 'echo "$1" > "$0"', sprintf('/sys/fs/cgroup/mutation-gate/gate-%d/cgroup.procs', getmypid()), sprintf('%d', getmypid())],
        ]);
})->skip(fn(): bool => ! function_exists('posix_geteuid'), 'the user is read through posix');

it('stops at the first step sudo refuses, and says the gate was not moved', function (int $refused): void {
    $ran = 0;

    $moved = CgroupDelegation::moved('/sys/fs/cgroup', static function (array $command) use (&$ran, $refused): bool {
        $ran++;

        return $ran !== $refused && $command !== [];
    });

    expect($moved)->toBeFalse()
        ->and($ran)->toBe($refused);
})->with([1, 2, 3, 4])->skip(fn(): bool => ! function_exists('posix_geteuid'), 'the user is read through posix');
