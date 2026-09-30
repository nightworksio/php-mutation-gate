<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

$console = static fn(): Application => Commands::console(Tree::root());

it('is the mutation-gate command line', function () use ($console): void {
    expect($console()->getName())->toBe('mutation-gate');
});

it('offers every command the README lists', function (string $command) use ($console): void {
    expect($console()->has($command))->toBeTrue();
})->with([
    'run',
    'plan',
    'verdict',
    'baseline',
    'reproduce',
    'triage',
    'watch',
    'pre-push',
    'hook',
    'init',
    'config:show',
    'config:schema',
    'pest:patch',
]);

it('runs the whole gate when no command is named', function () use ($console): void {
    $tester = new ApplicationTester($console());

    expect($tester->run([]))->toBe(2)
        ->and(Printed::by($tester->getOutput()))
        ->toBe("mutation-gate run is not built yet, so it cannot judge anything.\n");
});

it('accepts --no-extensions before any command, as a flag', function () use ($console): void {
    $options = $console()->getDefinition()->getOptions();

    expect(array_key_exists('no-extensions', $options))->toBeTrue()
        ->and(array_key_exists('no-extensions', $options) && $options['no-extensions']->acceptValue())->toBeFalse();
});

it('takes the config file, the runner and the reports on any command', function () use ($console): void {
    $definition = $console()->getDefinition();

    expect($definition->getOption('config')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('runner')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('report')->isArray())->toBeTrue()
        ->and($definition->getOption('report')->isValueRequired())->toBeTrue();
});

it('builds the config commands', function () use ($console): void {
    $tester = new ApplicationTester($console());

    expect($tester->run(['command' => 'config:schema']))->toBe(0);
});

it('answers with an exit code rather than ending the process', function () use ($console): void {
    expect($console()->isAutoExitEnabled())->toBeFalse();
});
