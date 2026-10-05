<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Measured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();

    foreach (SHELL_PROBES as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

/** Variables a test sets in the environment the gate runs in. */
const SHELL_PROBES = ['INFECTION_PROBE', 'MUTATION_GATE_PROBE', 'AWS_SECRET_ACCESS_KEY', 'GITHUB_TOKEN', 'ACTIONS_RUNTIME_TOKEN', 'KEPT_PROBE'];

/** A script that prints what it was run with, a variable to a line. */
const SHELL_PRINTS = 'foreach (["INFECTION_PROBE", "MUTATION_GATE_PROBE", "AWS_SECRET_ACCESS_KEY", "GITHUB_TOKEN",'
    . ' "ACTIONS_RUNTIME_TOKEN", "KEPT_PROBE"] as $n) { echo $n, "=", var_export(getenv($n), true), "\n"; }'
    . ' echo "PATH=", getenv("PATH"), "\n", getcwd(); fwrite(STDERR, "!");';

it('runs a script in its directory, and keeps both of its outputs', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), $directory, ['PATH' => '/usr/bin'])->run(Command::php('-r', 'echo getcwd(); fwrite(STDERR, "!");'));

    expect($ran)->toEqual(Ran::exited(0, sprintf('%s!', $directory))->took(Measured::of($ran)));
});

it('runs a script in another directory once moved there, on the PATH it had', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), '/', ['PATH' => '/usr/bin'])->in($directory)
        ->run(Command::php('-r', 'echo getcwd(), " ", getenv("PATH");'));

    expect($ran)->toEqual(Ran::exited(
        0,
        sprintf('%s %s%s/usr/bin', $directory, dirname(PHP_BINARY), PATH_SEPARATOR),
    )->took(Measured::of($ran)));
});

it('puts the running PHP first on the PATH, so every PHP it starts is the same', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), ['PATH' => '/usr/bin:/bin'])->run(Command::php('-r', SHELL_PRINTS));

    expect($ran->output())->toContain(sprintf("\nPATH=%s%s/usr/bin:/bin\n", dirname(PHP_BINARY), PATH_SEPARATOR));
});

it('withholds another run\'s variables and every credential, unless the command sets them', function (): void {
    foreach (SHELL_PROBES as $name) {
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
});

it('withholds every variable the command withholds', function (): void {
    putenv('KEPT_PROBE=inherited');
    $_SERVER['KEPT_PROBE'] = 'inherited';

    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), getenv())
        ->run(Command::php('-r', SHELL_PRINTS)->withholding(Withheld::of('KEPT_*')));

    expect($ran->output())->toContain("\nKEPT_PROBE=false\n");
});

it('says a script that exits with a failure did not succeed, and its exit code', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), [])->run(Command::php('-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::exited(3, 'no')->took(Measured::of($ran)));
});

it('answers a process that cannot start as a failure, with the reason', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), '/nowhere/at/all', [])->run(Command::php('-r', 'echo "never";'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'The provided cwd "/nowhere/at/all" does not exist.')
        ->took(Seconds::of(0.0)));
});

it('runs a command through the processes, in its directory, with its deadline, and ends as it ends', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(3, 'no'));

    $ran = new ProcessShell($processes, '/project', [])->run(Command::php('-v')->within(Seconds::of(4.0)));
    [$command] = $processes->ran();

    expect($ran)->toEqual(Ran::exited(3, 'no'))
        ->and($processes->ran())->toHaveCount(1)
        ->and($command->directory())->toBe('/project')
        ->and([...$command->arguments()])->toBe([PHP_BINARY, '-v'])
        ->and($command->deadline())->toEqual(Seconds::of(4.0));
});

it('unsets the variables that make a process another run\'s worker, even where only $_ENV holds one', function (): void {
    $_ENV['PARATEST'] = '1';
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), Scratch::directory(), [])->run(Command::php('-r', 'var_export(getenv("PARATEST"));'));
    unset($_ENV['PARATEST']);

    expect($ran->output())->toBe('false');
});
