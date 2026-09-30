<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Given;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/** The options every command takes, and those some commands take. */
$definition = static fn(): InputDefinition => new InputDefinition([
    new InputOption('config', mode: InputOption::VALUE_REQUIRED),
    new InputOption('runner', mode: InputOption::VALUE_REQUIRED),
    new InputOption('report', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
    new InputOption('budget', mode: InputOption::VALUE_REQUIRED),
    new InputOption('ci', mode: InputOption::VALUE_REQUIRED),
    new InputOption('no-extensions', mode: InputOption::VALUE_NONE),
]);

it('reads what the command line says about the config', function () use ($definition): void {
    $given = Given::from(new ArrayInput([
        '--config' => 'ci/gate.json',
        '--runner' => 'infection',
        '--report' => ['sarif:build/mutation.sarif', 'acme'],
        '--budget' => '15m',
        '--ci' => 'gitlab',
        '--no-extensions' => true,
    ], $definition()));

    expect($given->config)->toBe('ci/gate.json')
        ->and($given->firstPartyOnly)->toBeTrue()
        ->and($given->layer())->toBe([
            'runner' => 'infection',
            'reports' => [['use' => 'sarif', 'path' => 'build/mutation.sarif'], ['use' => 'acme']],
            'budget' => '15m',
            'ci' => ['plan' => 'gitlab'],
        ]);
});

it('sets nothing a command line does not say', function () use ($definition): void {
    $given = Given::from(new ArrayInput([], $definition()));

    expect($given->config)->toBe('')
        ->and($given->firstPartyOnly)->toBeFalse()
        ->and($given->layer())->toBe([]);
});

it('reads nothing from an option a command does not take', function (): void {
    $given = Given::from(new ArrayInput([], new InputDefinition()));

    expect([$given->config, $given->runner, $given->reports, $given->budget, $given->ci])->toBe(['', '', [], '', '']);
});

it('keeps a report path that holds a colon whole', function () use ($definition): void {
    expect(Given::from(new ArrayInput(['--report' => ['html:C:/build/html']], $definition()))->layer())
        ->toBe(['reports' => [['use' => 'html', 'path' => 'C:/build/html']]]);
});

it('says everything but the config file to read, where init writes one', function () use ($definition): void {
    $given = Given::from(new ArrayInput([
        '--config' => 'ci/gate.json',
        '--runner' => 'infection',
        '--report' => ['sarif:build/mutation.sarif'],
        '--budget' => '15m',
        '--ci' => 'gitlab',
        '--no-extensions' => true,
    ], $definition()))->withoutConfig();

    expect([$given->config, $given->runner, $given->reports, $given->budget, $given->ci, $given->firstPartyOnly])
        ->toBe(['', 'infection', ['sarif:build/mutation.sarif'], '15m', 'gitlab', true]);
});
