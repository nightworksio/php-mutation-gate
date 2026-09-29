<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('ships the JSON Schema config:schema prints', function (): void {
    // Regenerate with: vendor/bin/mutation-gate config:schema > resources/mutation-gate.schema.json
    expect((string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')))
        ->toBe(sprintf("%s\n", Definition::schema()));
});

it('writes JSON Schema draft 2020-12, published where the README says', function (): void {
    $schema = json_decode(Definition::schema(), associative: true);

    expect(is_array($schema) ? [$schema['$schema'], $schema['$id'], $schema['type'], $schema['required']] : [])->toBe([
        'https://json-schema.org/draft/2020-12/schema',
        'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v1/resources/mutation-gate.schema.json',
        'object',
        ['runner'],
    ]);
});

it('declares every setting as affecting results or as judging or reporting only', function (): void {
    $results = Effect::AffectsResults;
    $judges = Effect::JudgesOrReportsOnly;

    // ADR-0007 decision 2.3: what affects results is in a proof's key, and
    // every other setting is left out of it.
    expect(Definition::effects())->toBe([
        '$schema' => $judges,
        'extensions' => $judges,
        'preset' => $judges,
        'runner' => $results,
        'treeSource' => $results,
        'treeSource.with.fallback' => $results,
        'trees[].path' => $results,
        'trees[].floor' => $judges,
        'trees[].reason' => $judges,
        'newCode.floor' => $judges,
        'uncovered' => $judges,
        'baseline.path' => $judges,
        'baseline.improvement' => $judges,
        'packages' => $results,
        'reach.everything' => $judges,
        'holds.hotPath' => $judges,
        'shards.seconds' => $judges,
        'shards.max' => $judges,
        'costs.secondsPerLine' => $judges,
        'ci.plan' => $judges,
        'ci.defaultBranch' => $judges,
        'ci.gitlab.template' => $judges,
        'ci.buildkite.step' => $judges,
        'proofs.store' => $judges,
        'proofs.store.with.path' => $judges,
        'proofs.store.with.bucket' => $judges,
        'proofs.store.with.prefix' => $judges,
        'proofs.store.with.region' => $judges,
        'proofs.store.with.endpoint' => $judges,
        'proofs.ignore' => $judges,
        'proofs.write' => $judges,
        'budget' => $judges,
        'timeouts.mode' => $judges,
        'timeouts.seconds' => $results,
        'timeouts.retries' => $results,
        'flaky.confirmSurvivors' => $results,
        'ignores.entries' => $judges,
        'ignores.entries[].mutant' => $judges,
        'ignores.entries[].path' => $judges,
        'ignores.entries[].mutator' => $judges,
        'ignores.entries[].reason' => $judges,
        'ignores.entries[].expires' => $judges,
        'ignores.maxDays' => $judges,
        'ignores.native' => $judges,
        'reports' => $judges,
        'badge.colors' => $judges,
        'pest.patch' => $results,
        'pest.canary' => $results,
        'local.watchBudget' => $judges,
        'local.prePushBudget' => $judges,
    ]);
});

it('declares a setting for every key of the README\'s configuration reference', function (): void {
    $readme = (string) file_get_contents(Tree::at('README.md'));
    $from = (string) strstr($readme, '### Configuration reference');
    $reference = (string) strstr($from, 'In a `composer.json`', before_needle: true);
    preg_match_all('/^\| `([a-zA-Z$.\[\]]+)`(?: \(`[a-z0-9]+`\))? \|/m', $reference, $keys);

    expect(array_values(array_diff($keys[1], array_keys(Definition::effects()))))->toBe([]);
});
