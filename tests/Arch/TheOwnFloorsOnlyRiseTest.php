<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;

// G11: the package's own floors live in its baseline alone, which only rises.

/** @return array<mixed> */
function floorFileRead(string $file): array
{
    $decoded = json_decode((string) file_get_contents(Tree::at($file)), associative: true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param  array<mixed> $node
 * @return array<mixed>
 */
function floorFileMember(array $node, string $key): array
{
    return is_array($node[$key] ?? null) ? $node[$key] : [];
}

function floorFileTreePath(mixed $tree): string
{
    return is_array($tree) && is_string($tree['path'] ?? null) ? $tree['path'] : '?';
}

/**
 * Each floor the config declares where the baseline must hold it instead, by where it is declared.
 *
 * @param  array<mixed> $config
 * @return list<string>
 */
function floorsDeclaredIn(array $config): array
{
    $trees = [];

    foreach (floorFileMember($config, 'trees') as $tree) {
        $trees = is_array($tree) && array_key_exists('floor', $tree) ? [...$trees, sprintf('trees: %s', floorFileTreePath($tree))] : $trees;
    }

    $security = array_key_exists('floor', floorFileMember($config, 'security')) ? ['security'] : [];
    $newCode = floorFileMember($config, 'newCode')['floor'] ?? 100;

    return [...$trees, ...$security, ...($newCode === 100 ? [] : [sprintf('newCode: %s', is_scalar($newCode) ? (string) $newCode : '?')])];
}

/**
 * Each baseline entry that went down, by its set.
 *
 * @param  array<mixed> $baseline
 * @return list<string>
 */
function floorsLoweredIn(array $baseline): array
{
    $lowered = [];

    foreach (['trees', 'security'] as $sets) {
        foreach (floorFileMember($baseline, $sets) as $set => $entry) {
            $lowered = is_array($entry) && array_key_exists('lowered', $entry) ? [...$lowered, sprintf('%s: %s', $sets, $set)] : $lowered;
        }
    }

    return $lowered;
}

it('keeps the package\'s own floors in its baseline alone, where none went down', function (): void {
    $config = floorFileRead('mutation-gate.json');
    $baseline = floorFileRead('mutation-gate.baseline.json');
    $trees = array_map(floorFileTreePath(...), floorFileMember($config, 'trees'));
    $held = array_keys(floorFileMember($baseline, 'trees'));
    sort($trees);
    sort($held);

    // G11
    expect(floorsDeclaredIn($config))->toBe([], 'mutation-gate.json declares a floor the baseline should hold')
        ->and(floorsLoweredIn($baseline))->toBe([], 'mutation-gate.baseline.json lowers a floor')
        ->and($held)->toBe($trees, 'mutation-gate.baseline.json holds no floor for a tree mutation-gate.json lists');
});

it('names each floor a config declares, and each baseline entry that went down', function (): void {
    expect(floorsDeclaredIn([
        'trees' => [['path' => 'src', 'floor' => 90], ['path' => 'lib']],
        'security' => ['floor' => 100],
        'newCode' => ['floor' => 95],
    ]))->toBe(['trees: src', 'security', 'newCode: 95'])
        ->and(floorsDeclaredIn(['trees' => [['path' => 'src']], 'newCode' => ['floor' => 100]]))->toBe([])
        ->and(floorsLoweredIn([
            'trees' => ['src' => ['floor' => 90, 'lowered' => ['from' => 91, 'reason' => 'r']], 'lib' => ['floor' => 80]],
            'security' => ['nightworksio/mutation-gate' => ['floor' => 70, 'lowered' => ['from' => 75, 'reason' => 'r']]],
        ]))->toBe(['trees: src', 'security: nightworksio/mutation-gate']);
});
