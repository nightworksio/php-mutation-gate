<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Layers;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('lays a later config over an earlier one', function (array $earlier, array $later, mixed $merged): void {
    $document = Layers::over(Configs::document($earlier), Configs::document($later));

    expect($document instanceof Document ? json_decode($document->json(), associative: true) : $document)
        ->toBe($merged);
})->with([
    'maps merge by key' => [
        ['timeouts' => ['seconds' => 30, 'mode' => 'confirm']],
        ['timeouts' => ['seconds' => 60], 'budget' => '5m'],
        ['timeouts' => ['seconds' => 60, 'mode' => 'confirm'], 'budget' => '5m'],
    ],
    'lists concatenate without repeating an entry' => [
        ['reach' => ['everything' => ['config/**', 'routes/**']]],
        ['reach' => ['everything' => ['routes/**', '.env.test']]],
        ['reach' => ['everything' => ['config/**', 'routes/**', '.env.test']]],
    ],
    'an entry equal only in its looks is not a repeat' => [
        ['extensions' => [1]],
        ['extensions' => ['1', 1]],
        ['extensions' => [1, '1']],
    ],
    'an object in a list repeats only when it is equal' => [
        ['reports' => [['use' => 'sarif', 'path' => 'a']]],
        ['reports' => [['use' => 'sarif', 'path' => 'a'], ['use' => 'sarif', 'path' => 'b']]],
        ['reports' => [['use' => 'sarif', 'path' => 'a'], ['use' => 'sarif', 'path' => 'b']]],
    ],
    'a scalar replaces' => [
        ['uncovered' => 'count', 'preset' => 'laravel'],
        ['uncovered' => 'exclude'],
        ['uncovered' => 'exclude', 'preset' => 'laravel'],
    ],
    'a scalar replaces a map, and a map a scalar' => [
        ['ci' => 'github', 'budget' => ['m' => 5]],
        ['ci' => ['plan' => 'json'], 'budget' => '5m'],
        ['ci' => ['plan' => 'json'], 'budget' => '5m'],
    ],
    'trees replace the trees before them whole' => [
        ['trees' => [['path' => 'app']]],
        ['trees' => [['path' => 'src']]],
        ['trees' => [['path' => 'src']]],
    ],
    'a chosen adapter replaces the one before it, options and all' => [
        [
            'runner' => ['use' => 'pest', 'with' => ['a' => 1]],
            'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
            'ci' => ['plan' => ['use' => 'json', 'with' => ['b' => 2]]],
            'proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b']], 'write' => 'never'],
        ],
        [
            'runner' => ['use' => 'infection'],
            'treeSource' => ['use' => 'phpunit'],
            'ci' => ['plan' => ['use' => 'github']],
            'proofs' => ['store' => ['use' => 'directory']],
        ],
        [
            'runner' => ['use' => 'infection'],
            'treeSource' => ['use' => 'phpunit'],
            'ci' => ['plan' => ['use' => 'github']],
            'proofs' => ['store' => ['use' => 'directory'], 'write' => 'never'],
        ],
    ],
    'a map below the top merges too' => [
        ['ci' => ['gitlab' => ['template' => 'a.yml']]],
        ['ci' => ['buildkite' => ['step' => []]]],
        ['ci' => ['gitlab' => ['template' => 'a.yml'], 'buildkite' => ['step' => []]]],
    ],
    'an empty object takes the other side' => [
        [],
        ['runner' => 'pest'],
        ['runner' => 'pest'],
    ],
    'an empty later object changes nothing' => [
        ['runner' => 'pest'],
        [],
        ['runner' => 'pest'],
    ],
]);

it('merges decoded configs as they are', function (): void {
    expect(Layers::merged(['a' => ['b' => 1]], ['a' => ['c' => 2]]))->toBe(['a' => ['b' => 1, 'c' => 2]])
        ->and(Layers::merged('earlier', 'later'))->toBe('later');
});
