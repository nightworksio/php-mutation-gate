<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Console;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use Symfony\Component\Console\Tester\ApplicationTester;

it('is the mutation-gate command line', function (): void {
    expect(Console::application()->getName())->toBe('mutation-gate');
});

it('offers every command the README lists', function (string $command): void {
    expect(Console::application()->has($command))->toBeTrue();
})->with(['run', 'plan', 'verdict', 'baseline', 'reproduce', 'triage', 'watch', 'pre-push', 'hook', 'init', 'config:show', 'config:schema', 'pest:patch']);

it('runs the whole gate when no command is named', function (): void {
    $tester = new ApplicationTester(Console::application());

    expect($tester->run([]))->toBe(2)
        ->and(Printed::by($tester->getOutput()))->toBe("mutation-gate run is not built yet, so it cannot judge anything.\n");
});

it('accepts --no-extensions before any command, as a flag', function (): void {
    $options = Console::application()->getDefinition()->getOptions();

    expect(array_key_exists('no-extensions', $options))->toBeTrue()
        ->and(array_key_exists('no-extensions', $options) && $options['no-extensions']->acceptValue())->toBeFalse();
});

it('answers with an exit code rather than ending the process', function (): void {
    expect(Console::application()->isAutoExitEnabled())->toBeFalse();
});
