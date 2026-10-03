<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\ProcessShell;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ending;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** Variables a test sets in the environment the gate runs in. */
const PHPUNIT_SHELL_PROBES = ['MUTATION_GATE_RESULTS', 'PEST_MUTATION_PROBE', 'UNIQUE_TEST_TOKEN', 'GITHUB_TOKEN', 'DEPLOY_KEY', 'KEPT_PROBE'];

afterEach(function (): void {
    Scratch::sweep();

    foreach (PHPUNIT_SHELL_PROBES as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

it('runs PHP in its directory, keeps both outputs, and says it succeeded and how long it took', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell($directory, ['PATH' => '/usr/bin'])->run(Command::php('-r', 'echo getcwd(); fwrite(STDERR, "!");'));

    expect([$ran->ending(), $ran->output()])->toBe([Ending::Succeeded, sprintf('%s!', $directory)])
        ->and($ran->duration() instanceof Seconds ? $ran->duration()->seconds() : -1.0)->toBeGreaterThan(0.0);
});

it('runs PHP in another directory once moved there, with the running PHP first on the PATH', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $ran = new ProcessShell('/', ['PATH' => '/usr/bin'])->in($directory)
        ->run(Command::php('-r', 'echo getcwd(), " ", getenv("PATH");'));

    expect($ran->output())->toBe(sprintf('%s %s%s/usr/bin', $directory, dirname(PHP_BINARY), PATH_SEPARATOR));
});

it('says a program failed where it exits with an error, with its exit code, or cannot start, with none', function (): void {
    $failed = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', 'exit(3);'));
    $unstarted = new ProcessShell('/no/such/directory', [])->run(Command::php('-r', 'echo 1;'));

    expect($failed->ending())->toBe(Ending::Failed)
        ->and($failed->exitCode())->toBe(3)
        ->and($unstarted->ending())->toBe(Ending::Failed)
        ->and($unstarted->exitCode())->toEqual(NotGiven::value())
        ->and($unstarted->output())->toContain('/no/such/directory');
});

it('withholds another run\'s variables and what the command withholds, and tells what the command tells', function (): void {
    foreach (PHPUNIT_SHELL_PROBES as $name) {
        putenv(sprintf('%s=inherited', $name));
        $_SERVER[$name] = 'inherited';
    }

    $prints = 'foreach (["MUTATION_GATE_RESULTS", "PEST_MUTATION_PROBE", "UNIQUE_TEST_TOKEN", "GITHUB_TOKEN", "DEPLOY_KEY",'
        . ' "KEPT_PROBE"] as $n) { echo $n, "=", var_export(getenv($n), true), "\n"; }';
    $command = Command::php('-r', $prints)
        ->withholding(Withheld::of('DEPLOY_*'))
        ->telling(Variable::Results, 'told');
    $ran = new ProcessShell(Scratch::directory(), getenv())->run($command);

    expect($ran->output())->toBe(implode("\n", [
        "MUTATION_GATE_RESULTS='told'",
        'PEST_MUTATION_PROBE=false',
        'UNIQUE_TEST_TOKEN=false',
        'GITHUB_TOKEN=false',
        'DEPLOY_KEY=false',
        "KEPT_PROBE='inherited'",
        '',
    ]));
});

it('unsets the variables the gate sets for its extension and wrapper, even where only $_ENV holds one', function (): void {
    $_ENV[Variable::Guard->value] = 'leaked';
    $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
    $prints = 'foreach (["MUTATION_GATE_GUARD", "MUTATION_GATE_MUTANT", "LARAVEL_PARALLEL_TESTING", "MUTATION_GATE_RESULTS"] as $n)'
        . ' { echo $n, "=", var_export(getenv($n), true), "\n"; }';
    $ran = new ProcessShell(Scratch::directory(), [])->run(Command::php('-r', $prints)->telling(Variable::Results, 'told'));
    unset($_ENV[Variable::Guard->value], $_ENV['LARAVEL_PARALLEL_TESTING']);

    expect($ran->output())->toBe("MUTATION_GATE_GUARD=false\nMUTATION_GATE_MUTANT=false\nLARAVEL_PARALLEL_TESTING=false\nMUTATION_GATE_RESULTS='told'\n");
});

it('stops a program at its deadline, with every process it started', function (): void {
    $pids = sprintf('%s/child.pid', Scratch::directory());
    $script = sprintf(
        '$child = proc_open([PHP_BINARY, "-r", "sleep(30);"], [], $pipes); file_put_contents(%s, proc_get_status($child)["pid"]); sleep(30);',
        var_export($pids, return: true),
    );
    $ran = new ProcessShell(Scratch::directory(), getenv())->run(Command::php('-r', $script)->within(Seconds::of(3.0)));
    exec(sprintf('ps -o stat= -p %d', (int) file_get_contents($pids)), $state);

    expect($ran->ending())->toBe(Ending::Stopped)
        ->and($ran->duration() instanceof Seconds ? $ran->duration()->seconds() : 99.0)->toBeLessThan(20.0)
        ->and(array_filter($state, static fn(string $line): bool => ! str_starts_with(trim($line), 'Z')))->toBe([]);
});

it('has a capped command\'s PHP scan the cap\'s directory after those it inherits, or PHP\'s own, and an uncapped one only those', function (): void {
    $cap = sprintf('%s/cap', Scratch::directory());
    mkdir($cap);
    file_put_contents(sprintf('%s/memory-cap.ini', $cap), "memory_limit=64M\n");
    $own = sprintf('%s/own', Scratch::directory());
    mkdir($own);
    file_put_contents(sprintf('%s/own.ini', $own), "precision=7\n");
    $prints = Command::php('-r', 'echo getenv("PHP_INI_SCAN_DIR"), " ", ini_get("memory_limit"), " ", ini_get("precision");');
    $capped = $prints->scanning(DiskPath::of($cap));

    $inherited = new ProcessShell(Scratch::directory(), ['PHP_INI_SCAN_DIR' => $own])->run($capped);
    $phpsOwn = new ProcessShell(Scratch::directory(), [])->run($capped);
    $uncapped = new ProcessShell(Scratch::directory(), ['PHP_INI_SCAN_DIR' => $own])->run($prints);

    expect($inherited->output())->toBe(sprintf('%s%s%s 64M 7', $own, PATH_SEPARATOR, $cap))
        ->and($phpsOwn->output())->toStartWith(sprintf('%s%s 64M', PATH_SEPARATOR, $cap))
        ->and(explode(' ', $uncapped->output()))->toHaveCount(3)
        ->and(explode(' ', $uncapped->output())[0])->toBe($own)
        ->and(explode(' ', $uncapped->output())[1])->not->toBe('64M')
        ->and($capped->scanned())->toEqual(DiskPath::of($cap))
        ->and($prints->scanned())->toBe(Uncapped::Memory);
});
