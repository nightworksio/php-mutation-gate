<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Clock;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
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

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: sprintf('%s!', $directory))->taking($ran->took()));
});

it('runs a script in another directory once moved there, on the PATH it had', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell('/', ['PATH' => '/usr/bin'])->in($directory)
        ->run(Command::php('-r', 'echo getcwd(), " ", getenv("PATH");'));

    expect($ran)->toEqual(Ran::finished(
        succeeded: true,
        output: sprintf('%s %s%s/usr/bin', $directory, dirname(PHP_BINARY), PATH_SEPARATOR),
    )->taking($ran->took()));
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

it('withholds every variable the command withholds', function (): void {
    putenv('KEPT_PROBE=inherited');
    $_SERVER['KEPT_PROBE'] = 'inherited';

    $ran = new ProcessShell(Scratch::directory(), getenv())
        ->run(Command::php('-r', SHELL_PRINTS)->withholding(Withheld::of('KEPT_*')));

    expect($ran->output())->toContain("\nKEPT_PROBE=false\n");
});

it('says a script that exits with a failure did not succeed', function (): void {
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'no')->taking($ran->took()));
});

it('answers a process that cannot start as a failure, with the reason', function (): void {
    $ran = new ProcessShell('/nowhere/at/all', [])->run(Command::php('-r', 'echo "never";'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'The provided cwd "/nowhere/at/all" does not exist.'));
});

it('stops a script at its deadline with every process it started, keeping what it printed', function (): void {
    $directory = (string) realpath(Scratch::directory());
    // The child holds a lock for as long as it lives, and marks the tree unstopped if it outlives its sleep.
    $child = '$lock = fopen("alive", "c"); flock($lock, LOCK_EX); touch("ready"); sleep(20); touch("outlived");';
    $script = sprintf(
        'echo "started"; flush(); proc_open([PHP_BINARY, "-r", %s], [], $pipes); sleep(20);',
        var_export($child, return: true),
    );
    // The clock stands still until the child holds its lock, then passes every deadline, however slow starting was.
    $clock = new readonly class ($directory) implements Clock {
        public function __construct(private string $directory)
        {
        }

        public function nanoseconds(): int
        {
            return is_file(sprintf('%s/ready', $this->directory)) ? PHP_INT_MAX : 0;
        }
    };

    $ran = new ProcessShell($directory, ['PATH' => '/usr/bin:/bin'], $clock)
        ->run(Command::php('-r', $script)->within(Seconds::of(1.0)));
    // The lock is released the moment the child ends, so taking it waits for exactly that.
    $alive = fopen(sprintf('%s/alive', $directory), 'c');
    $ended = $alive !== false && flock($alive, LOCK_EX);

    expect($ran)->toEqual(Ran::stopped('started')->taking($ran->took()))
        ->and($ended)->toBeTrue()
        ->and(is_file(sprintf('%s/outlived', $directory)))->toBeFalse();
});

it('measures a deadline on the system\'s clock', function (): void {
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'sleep(20);')->within(Seconds::of(0.0)));

    expect($ran)->toEqual(Ran::stopped('')->taking($ran->took()));
});

it('waits for a script that ends before its deadline', function (): void {
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'echo "done";')->within(Seconds::of(10.0)));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: 'done')->taking($ran->took()));
});

it('measures how long a script ran on its clock, from its start to its end', function (): void {
    // The clock reads 10 s when the script starts, and 12.5 s at every look after.
    $clock = new class implements Clock {
        private bool $started = false;

        public function nanoseconds(): int
        {
            $nanoseconds = $this->started ? 12_500_000_000 : 10_000_000_000;
            $this->started = true;

            return $nanoseconds;
        }
    };
    $ran = new ProcessShell(Scratch::directory(), [], $clock)->run(Command::php('-r', 'echo "ok";'));

    expect($ran->took())->toEqual(Seconds::of(2.5))
        ->and($ran->output())->toBe('ok');
});
