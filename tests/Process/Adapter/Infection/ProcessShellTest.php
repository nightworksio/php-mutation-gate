<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\InfectionShells;
use NightWorksIO\MutationGate\Tests\Support\Measured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Infection/Command.php',
    'holds:src/Adapter/Infection/ProcessShell.php',
    'holds:src/Adapter/Process/LocalProcesses.php',
    'holds:src/Adapter/Process/Running.php',
    'holds:src/Core/Runner/EnvironmentRead.php',
    'holds:src/Core/Runner/Polling.php',
    'holds:src/Core/Runner/Withheld.php',
];

afterEach(function (): void {
    Scratch::sweep();

    foreach (InfectionShells::PROBES as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

/** A script that prints what it was run with, a variable to a line. */
const SHELL_PRINTS = 'foreach (["INFECTION_PROBE", "MUTATION_GATE_PROBE", "AWS_SECRET_ACCESS_KEY", "GITHUB_TOKEN",'
    . ' "ACTIONS_RUNTIME_TOKEN", "KEPT_PROBE"] as $n) { echo $n, "=", var_export(getenv($n), true), "\n"; }'
    . ' echo "PATH=", getenv("PATH"), "\n", getcwd(); fwrite(STDERR, "!");';

it('runs a script in its directory, and keeps both of its outputs', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), $directory, ['PATH' => '/usr/bin'])->run(Command::php('-r', 'echo getcwd(); fwrite(STDERR, "!");'));

    expect($ran)->toEqual(Ran::exited(0, sprintf('%s!', $directory), $directory)->took(Measured::of($ran)));
})->group(...$holds);

it('runs a script in another directory once moved there, on the PATH it had', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), '/', ['PATH' => '/usr/bin'])->in($directory)
        ->run(Command::php('-r', 'echo getcwd(), " ", getenv("PATH");'));

    $printed = sprintf('%s %s%s/usr/bin', $directory, dirname(PHP_BINARY), PATH_SEPARATOR);

    expect($ran)->toEqual(Ran::exited(0, $printed, $printed)->took(Measured::of($ran)));
})->group(...$holds);

it('puts the running PHP first on the PATH, so every PHP it starts is the same', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), ['PATH' => '/usr/bin:/bin'])->run(Command::php('-r', SHELL_PRINTS));

    expect($ran->output())->toContain(sprintf("\nPATH=%s%s/usr/bin:/bin\n", dirname(PHP_BINARY), PATH_SEPARATOR));
})->group(...$holds);

it('withholds another run\'s variables and every credential, unless the command sets them', function (): void {
    foreach (InfectionShells::PROBES as $name) {
        putenv(sprintf('%s=inherited', $name));
        $_SERVER[$name] = 'inherited';
    }

    $command = Command::php('-r', SHELL_PRINTS)->with(['MUTATION_GATE_PROBE' => 'set']);
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), getenv())->run($command);

    expect($ran->output())->toStartWith(implode("\n", [
        'INFECTION_PROBE=false',
        "MUTATION_GATE_PROBE='set'",
        'AWS_SECRET_ACCESS_KEY=false',
        'GITHUB_TOKEN=false',
        'ACTIONS_RUNTIME_TOKEN=false',
        "KEPT_PROBE='inherited'",
    ]));
})->group(...$holds);

it('withholds every variable the command withholds', function (): void {
    putenv('KEPT_PROBE=inherited');
    $_SERVER['KEPT_PROBE'] = 'inherited';

    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), getenv())
        ->run(Command::php('-r', SHELL_PRINTS)->withholding(Withheld::of('KEPT_*')));

    expect($ran->output())->toContain("\nKEPT_PROBE=false\n");
})->group(...$holds);

it('says a script that exits with a failure did not succeed, and its exit code', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), [])->run(Command::php('-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::exited(3, 'no', 'no')->took(Measured::of($ran)));
})->group(...$holds);

it('unsets the variables that make a process another run\'s worker, even where only $_ENV holds one', function (): void {
    $_ENV['PARATEST'] = '1';
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), [])->run(Command::php('-r', 'var_export(getenv("PARATEST"));'));
    unset($_ENV['PARATEST']);

    expect($ran->output())->toBe('false');
})->group(...$holds);

it('runs commands side by side, each in its directory, told its place, withholding what it withholds', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $told = Command::php('-r', 'echo getcwd(), " ", getenv("TEST_TOKEN"), " ", var_export(getenv("KEPT_PROBE"), true);')
        ->withholding(Withheld::of('KEPT_PROBE'));

    $ends = new ProcessShell(new LocalProcesses(new SystemClock()), $directory, ['PATH' => '/usr/bin', 'KEPT_PROBE' => 'secret'])
        ->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $told, $told);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))
        ->toBe([sprintf('%s 1 false', $directory), sprintf('%s 2 false', $directory)]);
})->group(...$holds);
