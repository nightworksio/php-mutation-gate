<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('reads each paths entry: its glob and what it sets of the mutators, the timeouts and the static check', function (): void {
    $entry = [
        'glob' => 'src/Legacy/**',
        'mutators' => ['sets' => ['laravel'], 'except' => ['default/Plus']],
        'timeouts' => ['seconds' => 60, 'most' => 600],
        'staticCheck' => ['tool' => 'none', 'before' => false],
    ];
    $layer = Configs::layer(['paths' => [$entry, ['glob' => 'src/Gen/*.php']]]);

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))
        ->toBe(['paths' => [$entry, ['glob' => 'src/Gen/*.php']]]);
});

it('refuses an entry with no glob, and every key it does not take', function (): void {
    expect(Configs::problems(Configs::layer(['paths' => [
        ['timeouts' => ['seconds' => 60]],
        ['glob' => 'src/**', 'timeouts' => ['mode' => 'unjudged'], 'staticCheck' => ['config' => 'phpstan.neon'], 'budget' => '5m'],
    ]])))->toBe([
        'paths[0].glob: expected a glob, got nothing',
        'paths[1].timeouts.mode: unknown key',
        'paths[1].staticCheck.config: unknown key',
        'paths[1].budget: unknown key',
    ]);
});

it('takes runner.pathRules as partitioned only, and refuses the modes not yet built', function (): void {
    $partitioned = Configs::layer(['runner' => ['use' => 'pest', 'pathRules' => 'partitioned']]);

    expect($partitioned instanceof Layer ? $partitioned->setup()->runnerLayer()->pathRules() : $partitioned)
        ->toBe(PathRuleMode::Partitioned)
        ->and(Configs::valid(['runner' => 'pest'])->setup()->runnerLayer()->pathRules())
        ->toBe(PathRuleMode::Partitioned)
        ->and(Configs::decoded(Configs::valid(['runner' => ['pathRules' => 'partitioned']])))
        ->toBe(['runner' => ['pathRules' => 'partitioned']])
        ->and(Configs::problems(Configs::layer(['runner' => ['use' => 'pest', 'pathRules' => 'perFile']])))
        ->toBe(['runner.pathRules: expected "partitioned", got "perFile"']);
});

it('declares paths and runner.pathRules in the schema', function (): void {
    $schema = Definition::schema();

    expect(Decoded::at($schema, 'properties', 'paths', 'items', 'required'))->toBe(['glob'])
        ->and(Decoded::keys($schema, 'properties', 'paths', 'items', 'properties'))
        ->toBe(['glob', 'mutators', 'timeouts', 'staticCheck'])
        ->and(Decoded::at($schema, '$defs', 'contextBlock', 'properties', 'paths', 'items', 'required'))->toBe(['glob']);
});
