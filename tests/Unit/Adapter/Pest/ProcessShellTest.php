<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

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

it('runs a program in another directory once moved there', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell('/')->in($directory)->run(Command::of(PHP_BINARY, '-r', 'echo getcwd();'));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: $directory));
});

it('says a program that exits with a failure did not succeed', function (): void {
    $ran = new ProcessShell(Scratch::directory())->run(Command::of(PHP_BINARY, '-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'no'));
});

it('stops a program at its deadline, keeping what it printed', function (): void {
    $command = Command::of(PHP_BINARY, '-r', 'echo "started"; flush(); sleep(10);')->within(Seconds::of(1.0));

    expect(new ProcessShell(Scratch::directory())->run($command))->toEqual(Ran::stopped('started'));
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new ProcessShell('/nowhere/at/all')->run(Command::of(PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});
