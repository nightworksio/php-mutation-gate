<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Presets;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A shipped preset, as a config file would write it. */
$written = static function (string $name): mixed {
    $preset = Lookup::in(Presets::registered(new Extensions(Origin::of('nightworksio/mutation-gate'))))
        ->preset(Name::of($name));

    return $preset instanceof Layer ? Configs::decoded($preset) : $preset->why();
};

it('is a layer of config, as data', function (string $preset, array $expected) use ($written): void {
    expect($written($preset))->toBe($expected);
})->with([
    'library, whose trees are the autoload paths where phpunit.xml has no source' => [
        'library',
        [
            'mutators' => ['sets' => ['security']],
            'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => []]],
            'newCode' => ['floor' => 100],
            'reach' => ['everything' => []],
            'timeouts' => ['seconds' => 10],
        ],
    ],
    'laravel' => [
        'laravel',
        [
            'mutators' => ['sets' => ['laravel', 'security']],
            'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
            'newCode' => ['floor' => 100],
            'reach' => ['everything' => ['bootstrap/**', 'config/**', 'routes/**', '.env.testing']],
            'timeouts' => ['seconds' => 30],
        ],
    ],
    'symfony, whose tree keeps src/Kernel.php' => [
        'symfony',
        [
            'mutators' => ['sets' => ['symfony', 'security']],
            'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['src']]],
            'newCode' => ['floor' => 100],
            'reach' => ['everything' => ['config/**', '.env.test', 'tests/bootstrap.php']],
            'timeouts' => ['seconds' => 30],
        ],
    ],
]);

it('reads back from what it writes, once a runner is chosen', function (string $preset) use ($written): void {
    $config = $written($preset);

    expect(Configs::validated([...(is_array($config) ? $config : []), 'runner' => 'pest']))
        ->toBeInstanceOf(Settings::class);
})->with(['library', 'laravel', 'symfony']);
