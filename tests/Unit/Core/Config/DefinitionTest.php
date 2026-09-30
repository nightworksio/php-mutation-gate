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

    expect(is_array($schema) ? [$schema['$schema'], $schema['$id'], $schema['type']] : [])->toBe([
        'https://json-schema.org/draft/2020-12/schema',
        'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v1/resources/mutation-gate.schema.json',
        'object',
    ]);
});

it('lets a config file leave the runner for zero-config to find', function (): void {
    $schema = json_decode(Definition::schema(), associative: true);

    expect(is_array($schema) && array_key_exists('required', $schema))->toBeFalse()
        ->and(is_array($schema) && is_array($schema['properties']) ? array_keys($schema['properties']) : [])
        ->toContain('runner');
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
        'runner.withhold' => $judges,
        'treeSource' => $results,
        'treeSource.with.fallback' => $results,
        'trees[].path' => $results,
        'trees[].floor' => $judges,
        'trees[].reason' => $judges,
        'trees[].exclude' => $results,
        'newCode.floor' => $judges,
        'uncovered' => $judges,
        'baseline.path' => $judges,
        'baseline.improvement' => $judges,
        'packages' => $results,
        'reach.everything' => $judges,
        'holds.hotPath' => $judges,
        'shards.seconds' => $judges,
        'shards.max' => $judges,
        'shards.target' => $judges,
        'shards.setup' => $judges,
        'costs.secondsPerLine' => $judges,
        'costs.perRunnerMinute' => $judges,
        'costs.perRunnerMinute.amount' => $judges,
        'costs.perRunnerMinute.currency' => $judges,
        'ci.plan' => $judges,
        'ci.defaultBranch' => $judges,
        'ci.check' => $judges,
        'ci.gitlab.template' => $results,
        'ci.buildkite.step' => $judges,
        'ci.buildkite.definition' => $results,
        'ci.azure.definition' => $results,
        'proofs.store' => $judges,
        'proofs.store.with.path' => $judges,
        'proofs.store.with.bucket' => $judges,
        'proofs.store.with.prefix' => $judges,
        'proofs.store.with.region' => $judges,
        'proofs.store.with.endpoint' => $judges,
        'proofs.store.with.publicUrl' => $judges,
        'proofs.ignore' => $judges,
        'proofs.write' => $judges,
        'budget' => $judges,
        'timeouts.mode' => $judges,
        'timeouts.seconds' => $results,
        'timeouts.retries' => $results,
        'flaky.confirmSurvivors' => $results,
        'tests.order' => $results,
        'ignores.entries' => $judges,
        'ignores.entries[].mutant' => $judges,
        'ignores.entries[].path' => $judges,
        'ignores.entries[].mutator' => $judges,
        'ignores.entries[].reason' => $judges,
        'ignores.entries[].expires' => $judges,
        'ignores.maxDays' => $judges,
        'ignores.native' => $judges,
        'equivalence.static' => $judges,
        'reports' => $judges,
        'reports[].with.urlEnv' => $judges,
        'reports[].with.url' => $judges,
        'reports[].with.secretEnv' => $judges,
        'reports[].with.endpoint' => $judges,
        'reports[].with.only' => $judges,
        'reports[].with.identity' => $judges,
        'reports[].with.colors' => $judges,
        'reports[].with.commit' => $judges,
        'badge.colors' => $judges,
        'pest.patch' => $results,
        'pest.canary' => $results,
        'staticCheck.tool' => $results,
        'staticCheck.config' => $results,
        'local.watchBudget' => $judges,
        'local.prePushBudget' => $judges,
    ]);
});

it('declares every key of the README\'s configuration reference, or the entries it lists', function (): void {
    $readme = (string) file_get_contents(Tree::at('README.md'));
    $from = (string) strstr($readme, '### Configuration reference');
    $reference = (string) strstr($from, 'In a `composer.json`', before_needle: true);
    preg_match_all('/^\| `([a-zA-Z$.\[\]]+)`(?: \(`[a-z0-9]+`\))? \|/m', $reference, $keys);

    $declared = array_keys(Definition::effects());
    $undeclared = array_filter($keys[1], static fn(string $key): bool => ! array_any(
        $declared,
        static fn(string $setting): bool => $setting === $key || str_starts_with($setting, sprintf('%s[].', $key)),
    ));

    expect(array_values($undeclared))->toBe([]);
});
