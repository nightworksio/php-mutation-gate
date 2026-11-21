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

it('hands over only a cgroup one level under the mount, and the gate\'s own one level under that', function (string $held, string $leaf, bool $below): void {
    expect(CgroupDelegation::isBelow('/sys/fs/cgroup', $held, $leaf))->toBe($below);
})->with([
    'the gate\'s' => ['/sys/fs/cgroup/mutation-gate', '/sys/fs/cgroup/mutation-gate/gate-7', true],
    'the mount itself' => ['/sys/fs/cgroup', '/sys/fs/cgroup/gate-7', false],
    'beside the mount' => ['/sys/fs/mutation-gate', '/sys/fs/mutation-gate/gate-7', false],
    'two levels down' => ['/sys/fs/cgroup/system.slice/mutation-gate', '/sys/fs/cgroup/system.slice/mutation-gate/gate-7', false],
    'a leaf beside its cgroup' => ['/sys/fs/cgroup/mutation-gate', '/sys/fs/cgroup/gate-7', false],
]);
