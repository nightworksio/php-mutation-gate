<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('lays a later layer over an earlier one, section by section', function (
    array $earlier,
    array $later,
    array $laid,
): void {
    expect(Configs::decoded(Configs::valid($earlier)->over(Configs::valid($later))))->toBe($laid);
})->with([
    'the settings of a section merge by key' => [
        ['timeouts' => ['seconds' => 30, 'mode' => 'confirm']],
        ['timeouts' => ['seconds' => 60], 'budget' => '5m'],
        ['budget' => '5m', 'timeouts' => ['mode' => 'confirm', 'seconds' => 60]],
    ],
    'lists concatenate without repeating an entry' => [
        ['reach' => ['everything' => ['config/**', 'routes/**']]],
        ['reach' => ['everything' => ['routes/**', '.env.test']]],
        ['reach' => ['everything' => ['config/**', 'routes/**', '.env.test']]],
    ],
    'a report repeats only when it is the same report' => [
        ['reports' => [['use' => 'sarif', 'path' => 'a']]],
        ['reports' => [['use' => 'sarif', 'path' => 'a'], ['use' => 'sarif', 'path' => 'b']]],
        ['reports' => [['use' => 'sarif', 'path' => 'a'], ['use' => 'sarif', 'path' => 'b']]],
    ],
    'an ignore repeats only when it is the same ignore' => [
        ['ignores' => ['entries' => [['mutant' => '3f9a1c2b7d04', 'reason' => 'a']]]],
        ['ignores' => ['entries' => [
            ['mutant' => '3f9a1c2b7d04', 'reason' => 'a'],
            ['mutant' => '3f9a1c2b7d04', 'reason' => 'b'],
        ]]],
        ['ignores' => ['entries' => [
            ['mutant' => '3f9a1c2b7d04', 'reason' => 'a'],
            ['mutant' => '3f9a1c2b7d04', 'reason' => 'b'],
        ]]],
    ],
    'presets and extensions add up' => [
        ['preset' => 'laravel', 'extensions' => ['Acme\\A']],
        ['preset' => ['laravel', 'acme'], 'extensions' => ['Acme\\B', 'Acme\\A']],
        ['extensions' => ['Acme\\A', 'Acme\\B'], 'preset' => ['laravel', 'acme']],
    ],
    'a value replaces' => [
        ['uncovered' => 'count', 'newCode' => ['floor' => 90]],
        ['uncovered' => 'exclude'],
        ['newCode' => ['floor' => 90], 'uncovered' => 'exclude'],
    ],
    'trees replace the trees before them whole' => [
        ['trees' => [['path' => 'app']]],
        ['trees' => [['path' => 'src']]],
        ['trees' => [['path' => 'src']]],
    ],
    'a chosen adapter replaces the one before it, options and all' => [
        [
            'runner' => ['use' => 'pest', 'with' => []],
            'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
            'ci' => ['plan' => ['use' => 'Acme\\Plan', 'with' => ['b' => 2]]],
            'proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b']], 'write' => 'never'],
        ],
        [
            'runner' => ['use' => 'infection'],
            'treeSource' => ['use' => 'composer'],
            'ci' => ['plan' => ['use' => 'github']],
            'proofs' => ['store' => ['use' => 'Acme\\Store']],
        ],
        [
            'runner' => 'infection',
            'treeSource' => 'composer',
            'ci' => ['plan' => 'github'],
            'proofs' => ['store' => 'Acme\\Store', 'write' => 'never'],
        ],
    ],
    'what a runner withholds only grows, whichever runner a later layer chooses' => [
        ['runner' => ['use' => 'pest', 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH']]],
        ['runner' => ['use' => 'Acme\\Runner', 'with' => ['a' => 1], 'withhold' => ['COMPOSER_AUTH', 'NPM_TOKEN']]],
        ['runner' => [
            'use' => 'Acme\\Runner',
            'with' => ['a' => 1],
            'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH', 'NPM_TOKEN'],
        ]],
    ],
    'a runner named alone keeps what an earlier layer withholds' => [
        ['runner' => ['use' => 'pest', 'withhold' => ['DEPLOY_*']]],
        ['runner' => 'infection'],
        ['runner' => ['use' => 'infection', 'withhold' => ['DEPLOY_*']]],
    ],
    'a layer that only withholds keeps the runner before it' => [
        ['runner' => 'pest'],
        ['runner' => ['withhold' => ['DEPLOY_*']]],
        ['runner' => ['use' => 'pest', 'withhold' => ['DEPLOY_*']]],
    ],
    'a section below the top merges too' => [
        ['ci' => ['gitlab' => ['template' => 'a.yml']]],
        ['ci' => ['buildkite' => ['step' => ['agents' => ['queue' => 'q']]]]],
        ['ci' => ['gitlab' => ['template' => 'a.yml'], 'buildkite' => ['step' => ['agents' => ['queue' => 'q']]]]],
    ],
    'a shard count set by seconds replaces one set by a target, and the other way round' => [
        ['shards' => ['target' => '20m', 'max' => 4]],
        ['shards' => ['seconds' => 600]],
        ['shards' => ['seconds' => 600, 'max' => 4]],
    ],
    'an empty layer takes the other side' => [
        [],
        ['runner' => 'pest'],
        ['runner' => 'pest'],
    ],
    'an empty later layer changes nothing' => [
        ['runner' => 'pest'],
        [],
        ['runner' => 'pest'],
    ],
]);

it('writes nothing and calls nothing where it sets nothing', function (): void {
    expect(Layer::none()->written(ProjectRoot::origin())->line())->toBe('{}')
        ->and(Layer::none()->php(ProjectRoot::origin()))->toEqual(PhpCalls::none());
});

it('holds only the parts it is given, each over the none of its kind', function (): void {
    expect(Configs::decoded(Layer::of(Configs::valid(['runner' => 'pest'])->setup(), Configs::valid(['budget' => '5m'])->triage())))
        ->toBe(['runner' => 'pest', 'budget' => '5m']);
});
