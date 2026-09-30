<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
    $ran = new ProcessShell($directory, ['PATH' => '/usr/bin'])->run(Command::php('-r', 'echo getcwd(); fwrite(STDERR, "!");'));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: sprintf('%s!', $directory)));
});

it('puts the running PHP first on the PATH, so every PHP it starts is the same', function (): void {
    $ran = new ProcessShell(Scratch::directory(), ['PATH' => '/usr/bin:/bin'])->run(Command::php('-r', SHELL_PRINTS));

    expect($ran->output())->toContain(sprintf("\nPATH=%s%s/usr/bin:/bin\n", dirname(PHP_BINARY), PATH_SEPARATOR));
});

it('withholds another run\'s variables and every credential, unless the command sets them', function (): void {
    foreach (SHELL_PROBES as $name) {
        putenv(sprintf('%s=inherited', $name));
        $_SERVER[$name] = 'inherited';
    }

    $command = Command::php('-r', SHELL_PRINTS)->with(['MUTATION_GATE_PROBE' => 'set']);
    $ran = new ProcessShell(Scratch::directory(), getenv())->run($command);

    expect($ran->output())->toStartWith(implode("\n", [
        'INFECTION_PROBE=false',
        "MUTATION_GATE_PROBE='set'",
        'AWS_SECRET_ACCESS_KEY=false',
        'GITHUB_TOKEN=false',
        'ACTIONS_RUNTIME_TOKEN=false',
        "KEPT_PROBE='inherited'",
    ]));
});

it('says a script that exits with a failure did not succeed', function (): void {
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'no'));
});

it('answers a process that cannot start as a failure, with the reason', function (): void {
    $ran = new ProcessShell('/nowhere/at/all', [])->run(Command::php('-r', 'echo "never";'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'The provided cwd "/nowhere/at/all" does not exist.'));
});

it('stops a script at its deadline with every process it started, keeping what it printed', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $script = 'echo "started"; flush(); $child = proc_open(["sleep", "30"], [], $pipes);'
        . ' file_put_contents("child.pid", proc_get_status($child)["pid"]); sleep(30);';
    $ran = new ProcessShell($directory, ['PATH' => '/usr/bin:/bin'])->run(Command::php('-r', $script)->within(Seconds::of(1.0)));
    exec(sprintf('kill -0 %d 2>/dev/null', (int) file_get_contents(sprintf('%s/child.pid', $directory))), $output, $alive);

    expect($ran)->toEqual(Ran::stopped('started'))
        ->and($alive)->not->toBe(0);
});

it('waits for a script that ends before its deadline', function (): void {
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'echo "done";')->within(Seconds::of(10.0)));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: 'done'));
});
