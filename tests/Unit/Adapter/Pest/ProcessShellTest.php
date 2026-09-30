<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs a program in its directory, with its environment, and keeps both of its outputs', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $command = Command::of(PHP_BINARY, '-r', 'echo getcwd(), " ", getenv("GATE"); fwrite(STDERR, "!");')
        ->with(['GATE' => 'on']);
    $ran = new ProcessShell($directory)->run($command);

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: sprintf('%s on!', $directory)));
});

it('says a program that exits with a failure did not succeed', function (): void {
    $ran = new ProcessShell(Scratch::directory())->run(Command::of(PHP_BINARY, '-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'no'));
});

it('stops a program at its deadline, keeping what it printed', function (): void {
    $command = Command::of(PHP_BINARY, '-r', 'echo "started"; flush(); sleep(10);')->within(Seconds::of(1.0));

    expect(new ProcessShell(Scratch::directory())->run($command))->toEqual(Ran::stopped('started'));
});

it('stops every process a program started, at any depth, when it stops the program', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $grandchild = 'file_put_contents("grandchild", getmypid()); sleep(30);';
    $child = sprintf(
        'proc_open([PHP_BINARY, "-r", %s], [], $pipes); sleep(30);',
        var_export($grandchild, return: true),
    );
    $program = sprintf('proc_open([PHP_BINARY, "-r", %s], [], $pipes); sleep(30);', var_export($child, return: true));

    $ran = new ProcessShell($directory)->run(Command::of(PHP_BINARY, '-r', $program)->within(Seconds::of(2.0)));
    $pid = (string) file_get_contents(sprintf('%s/grandchild', $directory));
    // Gone within two seconds: no longer listed, or a zombie waiting to be reaped.
    $gone = new Process(['sh', '-c', sprintf(
        'for i in $(seq 40); do s=$(ps -o stat= -p %s); case "$s" in ""|Z*) exit 0;; esac; sleep 0.05; done; exit 1',
        $pid,
    )]);
    $gone->run();

    expect($ran)->toEqual(Ran::stopped(''))
        ->and($pid)->not->toBe('')
        ->and($gone->isSuccessful())->toBeTrue();
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new ProcessShell('/nowhere/at/all')->run(Command::of(PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});
