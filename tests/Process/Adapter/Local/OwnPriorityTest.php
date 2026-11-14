<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Local\OwnPriority;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The niceness a PHP process waiting on its input had after this lowered it, it ended after.
 *
 * @param Closure(int): void $lowering
 */
function lowered(Closure $lowering): int
{
    $process = proc_open([PHP_BINARY, '-r', 'fgets(STDIN);'], [0 => ['pipe', 'r']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('PHP could not be started.');
    }

    $pid = proc_get_status($process)['pid'];

    try {
        $lowering($pid);
        $niceness = pcntl_getpriority($pid);
    } finally {
        fclose($pipes[0]);
        proc_close($process);
    }

    return is_int($niceness) ? $niceness : throw new RuntimeException('The niceness could not be read.');
}

it('raises a process\'s niceness on Linux, never lowering it again', function (): void {
    $start = (int) pcntl_getpriority((int) getmypid());

    expect(lowered(static function (int $pid) use ($start): void {
        $priority = OwnPriority::on(System::Linux, $pid);
        $priority->lowered(MacosClass::None, $start + 3, WindowsClass::Normal);
        $priority->lowered(MacosClass::None, $start + 1, WindowsClass::Normal);
    }))->toBe($start + 3);
});

it('takes the utility niceness for macOS\'s utility class, which a running process cannot be clamped to', function (): void {
    $start = (int) pcntl_getpriority((int) getmypid());

    expect(lowered(static function (int $pid): void {
        OwnPriority::on(System::Darwin, $pid)->lowered(MacosClass::Utility, 19, WindowsClass::Normal);
    }))->toBe(max($start, Priority::Utility->nice()));
});

it('lowers its own process to the Windows class\'s niceness there', function (): void {
    $root = dirname(__DIR__, 4);
    $script = Scratch::directory();
    Scratch::write($script, 'lower.php', sprintf(<<<'PHP'
        <?php
        require %s;
        $before = pcntl_getpriority();
        NightWorksIO\MutationGate\Adapter\Local\OwnPriority::on(NightWorksIO\MutationGate\Core\Local\System::Windows, 0)
            ->lowered(NightWorksIO\MutationGate\Core\Local\MacosClass::None, 0, NightWorksIO\MutationGate\Core\Local\WindowsClass::Idle);
        echo $before, ' ', pcntl_getpriority();
        PHP, var_export(sprintf('%s/vendor/autoload.php', $root), return: true)));
    $process = proc_open([PHP_BINARY, sprintf('%s/lower.php', $script)], [1 => ['pipe', 'w']], $pipes, $script);
    $said = is_resource($process) ? stream_get_contents($pipes[1]) : '';

    if (is_resource($process)) {
        proc_close($process);
    }

    [$before, $after] = array_map(intval(...), explode(' ', (string) $said));

    expect($after)->toBe(min(19, $before + WindowsClass::Idle->nice()));
});

it('puts a process in macOS\'s background for the background and maintenance classes and takes it out again, by taskpolicy, once each change', function (): void {
    $bin = Scratch::directory();
    Scratch::write($bin, 'taskpolicy', "#!/bin/sh\necho \"$*\" >> \"$0.asked\"\n");
    chmod(sprintf('%s/taskpolicy', $bin), 0o755);
    $priority = OwnPriority::on(System::Darwin, 4242, sprintf('%s/taskpolicy', $bin));

    $priority->lowered(MacosClass::Background, 0, WindowsClass::Normal);
    $priority->lowered(MacosClass::Maintenance, 0, WindowsClass::Normal);
    $priority->lowered(MacosClass::None, 0, WindowsClass::Normal);

    expect(file_get_contents(sprintf('%s/taskpolicy.asked', $bin)))->toBe("-b -p 4242\n-B -p 4242\n");
});

it('is this process\'s own on this machine\'s system', function (): void {
    expect(OwnPriority::here())->toEqual(OwnPriority::on(System::here(), (int) getmypid()));
});
