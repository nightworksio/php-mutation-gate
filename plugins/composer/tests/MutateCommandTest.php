<?php

declare(strict_types=1);

use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use NightWorksIO\MutationGateComposer\MutateCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

/** What Composer's local repository lists where it installed the gate. */
const MUTATE_INSTALLED = '{"packages": [{"name": "nightworksio/mutation-gate", "version": "0.1.0", "version_normalized": "0.1.0.0", "type": "library"}]}';

/** A project of its own for one test, in an empty directory. */
function mutateProject(): string
{
    $directory = (string) tempnam(sys_get_temp_dir(), 'mutate');
    unlink($directory);
    mkdir(sprintf('%s/vendor/composer', $directory), recursive: true);
    mkdir(sprintf('%s/bin', $directory));
    file_put_contents(sprintf('%s/composer.json', $directory), '{"name": "acme/app", "version": "1.0.0", "config": {"bin-dir": "bin"}}');

    return $directory;
}

/** The project with the gate installed, as a command that prints each argument it was given on a line of its own, and exits 3. */
function mutateInstalled(string $directory): string
{
    $gate = sprintf('%s/bin/mutation-gate', $directory);
    file_put_contents($gate, "#!/bin/sh\nprintf 'gate:%s\\n' \"\$@\"\nexit 3\n");
    chmod($gate, 0o755);
    file_put_contents(sprintf('%s/vendor/composer/installed.json', $directory), MUTATE_INSTALLED);
    mkdir(sprintf('%s/vendor/nightworksio/mutation-gate', $directory), recursive: true);

    return $directory;
}

/**
 * `composer mutate` in the project, with these arguments: its exit code, and what it printed.
 *
 * @param  list<string> $arguments
 * @return array{int, string}
 */
function mutateRun(string $directory, array $arguments): array
{
    $io = new BufferIO();
    $command = new MutateCommand();
    $composer = new Factory()->createComposer($io, sprintf('%s/composer.json', $directory), disablePlugins: true, cwd: $directory, disableScripts: true);
    $command->setComposer($composer);
    $command->setIO($io);

    $code = new CommandTester($command)->execute(['command' => 'mutate', 'args' => $arguments]);
    new Filesystem()->removeDirectory($directory);

    return [$code, $io->getOutput()];
}

afterEach(function (): void {
    ProcessExecutor::setTimeout(300);
});

it('runs the gate from the bin directory with the arguments as given, with no time limit, and exits as it does', function (): void {
    [$code, $output] = mutateRun(mutateInstalled(mutateProject()), ['run', '--budget=1s', 'two words']);

    expect($code)->toBe(3)
        ->and($output)->toBe("gate:run\ngate:--budget=1s\ngate:two words\n")
        ->and(ProcessExecutor::getTimeout())->toBe(0);
});

it('runs the gate with nothing where it is given nothing', function (): void {
    expect(mutateRun(mutateInstalled(mutateProject()), []))->toBe([3, "gate:\n"]);
});

it('says how to install the gate where the project has not, runs nothing and exits 2', function (): void {
    $project = mutateProject();
    file_put_contents(sprintf('%s/bin/mutation-gate', $project), "#!/bin/sh\necho ran\n");
    chmod(sprintf('%s/bin/mutation-gate', $project), 0o755);

    expect(mutateRun($project, ['run']))
        ->toBe([2, "The gate is not installed. Install it: composer require --dev nightworksio/mutation-gate\n"]);
});

/**
 * What `mutate` takes: its arguments' names, whether its one argument takes any number of words, and is required, and its options.
 *
 * @return list{list<int|string>, bool, bool, array<InputOption>}
 */
function mutateTakes(): array
{
    $definition = new MutateCommand()->getDefinition();
    $arguments = $definition->getArguments();

    return [array_keys($arguments), $arguments['args']->isArray(), $arguments['args']->isRequired(), $definition->getOptions()];
}

it('takes any option the gate takes, and hands each on', function (): void {
    expect(mutateTakes())->toBe([['args'], true, false, []]);
});
