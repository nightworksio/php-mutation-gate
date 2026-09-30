<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;
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
    $given = CommandLine::from(new ArrayInput([
        '--config' => 'ci/gate.json',
        '--runner' => 'infection',
        '--report' => ['sarif:build/mutation.sarif', 'acme'],
        '--budget' => '15m',
        '--ci' => 'gitlab',
        '--no-extensions' => true,
    ], $definition()));

    expect($given->config)->toBe('ci/gate.json')
        ->and($given->firstPartyOnly)->toBeTrue()
        ->and(json_decode($given->written()->line(), associative: true))->toBe([
            'runner' => 'infection',
            'reports' => [['use' => 'sarif', 'path' => 'build/mutation.sarif'], ['use' => 'acme']],
            'budget' => '15m',
            'ci' => ['plan' => 'gitlab'],
        ]);
});

it('sets nothing a command line does not say', function () use ($definition): void {
    $given = CommandLine::from(new ArrayInput([], $definition()));

    expect($given->config)->toEqual(NotGiven::value())
        ->and($given)->toEqual(CommandLine::nothing())
        ->and($given->written()->line())->toBe('{}')
        ->and($given->layer())->toEqual(Layer::none());
});

it('reads nothing from an option a command does not take', function (): void {
    $given = CommandLine::from(new ArrayInput([], new InputDefinition()));

    expect($given)->toEqual(CommandLine::nothing());
});

it('keeps a report path that holds a colon whole', function () use ($definition): void {
    $layer = CommandLine::from(new ArrayInput(['--report' => ['html:C:/build/html']], $definition()))->layer();

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))
        ->toBe(['reports' => [['use' => 'html', 'path' => 'C:/build/html']]]);
});

it('says everything but the config file to read, where init writes one', function () use ($definition): void {
    $given = CommandLine::from(new ArrayInput([
        '--config' => 'ci/gate.json',
        '--runner' => 'infection',
        '--report' => ['sarif:build/mutation.sarif'],
        '--budget' => '15m',
        '--ci' => 'gitlab',
        '--no-extensions' => true,
    ], $definition()))->withoutConfig();

    expect([$given->config, $given->runner, $given->reports, $given->budget, $given->ci, $given->firstPartyOnly])
        ->toEqual([NotGiven::value(), 'infection', ['sarif:build/mutation.sarif'], '15m', 'gitlab', true]);
});

it('reads what the command line sets as a layer, each problem at the setting it writes', function () use (
    $definition,
): void {
    $valid = CommandLine::from(new ArrayInput(['--runner' => 'pest', '--budget' => '15m'], $definition()))->layer();
    $invalid = CommandLine::from(new ArrayInput(['--budget' => 'soon', '--report' => ['html']], $definition()))
        ->layer();

    expect($valid instanceof Layer ? Configs::decoded($valid) : Configs::problems($valid))
        ->toBe(['runner' => 'pest', 'budget' => '15m'])
        ->and(Configs::problems($invalid))->toBe([
            'budget: expected a duration such as 90s, 15m or 1h30m, got "soon"',
            'reports[0].path: expected a path, got nothing',
        ]);
});

it('builds what a command line says, option by option', function () use ($definition): void {
    $read = CommandLine::from(new ArrayInput([
        '--config' => 'ci/gate.json',
        '--runner' => 'infection',
        '--report' => ['sarif:build/mutation.sarif', 'acme'],
        '--budget' => '15m',
        '--ci' => 'gitlab',
        '--no-extensions' => true,
    ], $definition()));
    $built = CommandLine::nothing()
        ->withConfig('ci/gate.json')
        ->withRunner('infection')
        ->withReport('sarif:build/mutation.sarif')
        ->withReport('acme')
        ->withBudget('15m')
        ->withCi('gitlab')
        ->firstPartyOnly();

    expect($built)->toEqual($read);
});

it('writes an option given empty, for the config to refuse, rather than read it as left off', function () use (
    $definition,
): void {
    $layer = CommandLine::from(new ArrayInput(['--runner' => '', '--budget' => ''], $definition()))->layer();

    expect(Configs::problems($layer))->not->toBe([])
        ->and(CommandLine::from(new ArrayInput(['--runner' => ''], $definition()))->runner)->toBe('');
});

it('chooses a runner only where the command line chooses none', function (): void {
    $none = CommandLine::nothing()->withConfig('gate.json');
    $pest = CommandLine::nothing()->withRunner('pest');

    expect($none->choosing('infection'))->toEqual(CommandLine::nothing()->withConfig('gate.json')->withRunner('infection'))
        ->and($pest->choosing('infection'))->toBe($pest);
});
