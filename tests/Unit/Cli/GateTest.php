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

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['command' => 'plan']), $output, $errors))->toBe(2)
        ->and($output->fetch())->toBe("mutation-gate plan is not built yet, so it cannot judge anything.\n")
        ->and($errors->fetch())->toBe('');
});

it('stops before any command when the extensions cannot be loaded', function () use ($conflicted): void {
    $project = $conflicted();
    $output = new BufferedOutput();
    $errors = new BufferedOutput();

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['command' => 'plan']), $output, $errors))->toBe(2)
        ->and($output->fetch())->toBe('')
        ->and($errors->fetch())->toBe("Two packages register a runner named \"fake\": acme/one and acme/two. Remove one of the packages, or run with --no-extensions.\n");
});

it('loads this package\'s own extension alone under --no-extensions', function () use ($conflicted): void {
    $project = $conflicted();
    $output = new BufferedOutput();

    expect(Gate::in($project, sprintf('%s/vendor', $project))->run(new ArrayInput(['--no-extensions' => true, 'command' => 'plan']), $output, new BufferedOutput()))->toBe(2)
        ->and($output->fetch())->toBe("mutation-gate plan is not built yet, so it cannot judge anything.\n");
});

it('reads --no-extensions as an option only before the end of the options', function () use ($conflicted): void {
    $project = $conflicted();
    $errors = new BufferedOutput();

    Gate::in($project, sprintf('%s/vendor', $project))->run(new ArgvInput(['mutation-gate', 'reproduce', '--', '--no-extensions']), new BufferedOutput(), $errors);

    expect($errors->fetch())->toContain('Two packages register a runner named "fake"');
});

it('runs from its binary, with exit code 2 for a command not built', function (): void {
    $process = new Process([PHP_BINARY, 'bin/mutation-gate', 'verdict', '--plan=.mutation-gate/plan.json'], Tree::root());
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe("mutation-gate verdict is not built yet, so it cannot judge anything.\n");
});
