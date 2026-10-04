<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Gate;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in which two packages both register the fake runner. */
$conflicted = static function (): string {
    $project = Scratch::directory();
    $declaring = static fn(string $name): array => ['name' => $name, 'extra' => ['mutation-gate' => ['extensions' => [ExtensionFake::class]]]];
    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode(['packages' => [$declaring('acme/one'), $declaring('acme/two')]]));

    return $project;
};

it('hands the command line to the console once the extensions are found', function (): void {
    $project = Scratch::directory();
    $output = new BufferedOutput();
    $errors = new BufferedOutput();

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['command' => 'triage', 'path' => 'src', '--repeat' => '1']), $output, $errors))->toBe(2)
        ->and($output->fetch())->toBe("--repeat takes a whole number of 2 or more, not \"1\".\n")
        ->and($errors->fetch())->toBe('');
});

it('stops before any command when the extensions cannot be loaded', function () use ($conflicted): void {
    $project = $conflicted();
    $output = new BufferedOutput();
    $errors = new BufferedOutput();

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['command' => 'triage', 'path' => 'src', '--repeat' => '1']), $output, $errors))->toBe(2)
        ->and($output->fetch())->toBe('')
        ->and($errors->fetch())->toBe("Two packages register a runner named \"fake\": acme/one and acme/two. Remove one of the packages, or run with --no-extensions.\n");
});

it('loads this package\'s own extension alone under --no-extensions', function () use ($conflicted): void {
    $project = $conflicted();
    $output = new BufferedOutput();

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['--no-extensions' => true, 'command' => 'triage', 'path' => 'src', '--repeat' => '1']), $output, new BufferedOutput()))->toBe(2)
        ->and($output->fetch())->toBe("--repeat takes a whole number of 2 or more, not \"1\".\n");
});

it('reads --no-extensions as an option only before the end of the options', function () use ($conflicted): void {
    $project = $conflicted();
    $errors = new BufferedOutput();

    Gate::in($project, sprintf('%s/vendor', $project))->run(new ArgvInput(['mutation-gate', 'reproduce', '--', '--no-extensions']), new BufferedOutput(), $errors);

    expect($errors->fetch())->toContain('Two packages register a runner named "fake"');
});

it('runs from its binary, with exit code 2 for a command that cannot judge', function (): void {
    $process = new Process([PHP_BINARY, 'bin/mutation-gate', 'triage', 'src', '--repeat=1'], Tree::root());
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("--repeat takes a whole number of 2 or more, not \"1\".\n");
});

it('raises its own memory_limit to what its ledgers may need, and doctor says where PHP will not let it', function (): void {
    $doctor = static function (string ...$settings): string {
        $process = new Process([PHP_BINARY, ...$settings, 'bin/mutation-gate', 'doctor', '--format=json'], Tree::root());
        $process->run();

        return $process->getOutput();
    };

    $raised = $doctor('-d', 'memory_limit=64M');

    expect($raised)->toContain('"findings"')
        ->and(str_contains($raised, 'memory-limit-low'))->toBeFalse()
        ->and($doctor('-d', 'memory_limit=64M', '-d', 'disable_functions=ini_set'))->toContain('"slug": "memory-limit-low"');
});

it('raises a lower memory_limit to what its ledgers may need, and leaves a higher one or none alone', function (
    string $given,
    string $ended,
): void {
    $watch = Scratch::directory();
    Scratch::write($watch, 'limit.php', <<<'PHP'
        <?php register_shutdown_function(static function (): void {
            fwrite(STDERR, sprintf('memory_limit=%s', ini_get('memory_limit')));
        });
        PHP);
    $process = new Process(
        [PHP_BINARY, '-d', sprintf('memory_limit=%s', $given), '-d', sprintf('auto_prepend_file=%s/limit.php', $watch), 'bin/mutation-gate', '--version'],
        Tree::root(),
    );
    $process->run();

    expect($process->getErrorOutput())->toEndWith(sprintf('memory_limit=%s', $ended));
})->with([
    'a lower limit' => ['64M', '1578217728'],
    'a higher limit' => ['2G', '2G'],
    'no limit' => ['-1', '-1'],
]);
