<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('ships the JSON Schema config:schema prints', function (): void {
    // Regenerate with: vendor/bin/mutation-gate config:schema > resources/mutation-gate.schema.json
    expect((string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')))
        ->toBe(sprintf("%s\n", Definition::schema()));
});

it('writes JSON Schema draft 2020-12, published where the configuration reference says', function (): void {
    $schema = json_decode(Definition::schema(), associative: true);

    expect(is_array($schema) ? [$schema['$schema'], $schema['$id'], $schema['type']] : [])->toBe([
        'https://json-schema.org/draft/2020-12/schema',
        'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v0.1/resources/mutation-gate.schema.json',
        'object',
    ]);
});

it('lets a config file leave the runner for zero-config to find', function (): void {
    $schema = json_decode(Definition::schema(), associative: true);

    expect(is_array($schema) && array_key_exists('required', $schema))->toBeFalse()
        ->and(is_array($schema) && is_array($schema['properties']) ? array_keys($schema['properties']) : [])
        ->toContain('runner');
});

it('declares every setting as affecting results, as deciding how the gate runs, or as judging or reporting only', function (): void {
    $results = Effect::AffectsResults;
    $decides = Effect::DecidesHowTheGateRuns;
    $judges = Effect::JudgesOrReportsOnly;

    // ADR-0007 decision 2.3: what affects results is in a proof's key, and
    // every other setting is left out of it. ADR-0005 decision 4: a change
    // to any setting but one that only judges or reports reaches everything,
    // so each judge here was reviewed as unable to change a proof or a reach.
    expect(Definition::effects())->toBe([
        '$schema' => $judges,
        'extensions' => $results,
        'preset' => $decides,
        'runner' => $results,
        'runner.withhold' => $results,
        'runner.memory' => $results,
        'runner.workers' => $results,
        'treeSource' => $results,
        'treeSource.with.fallback' => $results,
        'trees[].path' => $results,
        'trees[].floor' => $judges,
        'trees[].reason' => $judges,
        'trees[].exclude' => $results,
        'newCode.floor' => $judges,
        'security.floor' => $judges,
        'uncovered' => $judges,
        'baseline.path' => $judges,
        'baseline.improvement' => $judges,
        'packages' => $results,
        'reach.everything' => $decides,
        'holds.hotPath' => $judges,
        'run.full' => $decides,
        'shards.seconds' => $judges,
        'shards.max' => $judges,
        'shards.target' => $judges,
        'shards.setup' => $judges,
        'costs.secondsPerLine' => $judges,
        'costs.perRunnerMinute' => $judges,
        'costs.perRunnerMinute.amount' => $judges,
        'costs.perRunnerMinute.currency' => $judges,
        'ci.plan' => $decides,
        'ci.defaultBranch' => $decides,
        'ci.check' => $decides,
        'ci.trustMergedPullRequests' => $decides,
        'ci.gitlab.template' => $results,
        'ci.buildkite.step' => $results,
        'ci.buildkite.definition' => $results,
        'ci.azure.definition' => $results,
        'ci.bitbucket.definition' => $results,
        'ci.jenkins.definition' => $results,
        'proofs.store' => $decides,
        'proofs.store.with.path' => $decides,
        'proofs.store.with.bucket' => $decides,
        'proofs.store.with.prefix' => $decides,
        'proofs.store.with.region' => $decides,
        'proofs.store.with.endpoint' => $decides,
        'proofs.store.with.insecureEndpoint' => $decides,
        'proofs.store.with.publicUrl' => $decides,
        'proofs.store.with.account' => $decides,
        'proofs.store.with.container' => $decides,
        'proofs.store.with.publicContainer' => $decides,
        'proofs.ignore' => $decides,
        'proofs.write' => $decides,
        'budget' => $judges,
        'timeouts.mode' => $judges,
        'timeouts.seconds' => $results,
        'timeouts.most' => $results,
        'timeouts.tighter.mutators' => $results,
        'timeouts.tighter.floor' => $results,
        'flaky.confirmSurvivors' => $results,
        'tests.order' => $results,
        'tests.suites' => $results,
        'tests.holding' => $results,
        'survivorsFirst.max' => $judges,
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
        'staticCheck.seconds' => $results,
        'staticCheck.before' => $judges,
        'pruning.enabled' => $results,
        'pruning.window' => $results,
        'pruning.audit' => $judges,
        'mutators.sets' => $results,
        'mutators.except' => $results,
        'local.watchBudget' => $judges,
        'local.prePushBudget' => $judges,
        'coverage.incremental' => $decides,
    ]);
});

it('declares every key of the configuration reference, or the entries it lists', function (): void {
    $from = (string) strstr((string) file_get_contents(Tree::at('.docs/reference/configuration.md')), '## Every key');
    $reference = (string) strstr($from, 'In a `composer.json`', before_needle: true);
    preg_match_all('/^\| `([a-zA-Z$.\[\]]+)`(?: \(`[a-z0-9]+`\))? \|/m', $reference, $keys);

    $declared = array_keys(Definition::effects());
    $undeclared = array_filter($keys[1], static fn(string $key): bool => ! array_any(
        $declared,
        static fn(string $setting): bool => $setting === $key || str_starts_with($setting, sprintf('%s[].', $key)),
    ));

    expect(array_values($undeclared))->toBe([]);
});

it('states the default budgets the configuration reference gives watch and pre-push', function (): void {
    $reference = (string) file_get_contents(Tree::at('.docs/reference/configuration.md'));
    $default = static fn(string $key): string => preg_match(
        sprintf('/^\\| `%s` \\| [^|]+ \\| `([^`]+)` \\|/m', preg_quote($key, '/')),
        $reference,
        $row,
    ) === 1 ? $row[1] : sprintf('no row for %s', $key);

    expect([$default('local.watchBudget'), $default('local.prePushBudget')])
        ->toBe([Budgets::standard()->watch()->written(), Budgets::standard()->prePush()->written()]);
});

it('gives runner.memory the default the configuration reference names', function (): void {
    $reference = (string) file_get_contents(Tree::at('.docs/reference/configuration.md'));
    preg_match('/^\| `runner\.memory` \|.*\| `([^`]+)` \| \[0004\]/m', $reference, $row);

    expect($row[1] ?? null)->toBe(MemoryCap::standard()->written());
});

it('reads a config file that writes what a release retired as that release\'s file, naming the change and migrate', function (): void {
    $migrations = Migrations::of(Migration::in('2.0.0', Rename::of('runnr', 'runner')));
    $old = Json::parse('{"runnr": "pest"}');
    $current = Json::parse('{"runner": "pest"}');

    expect($old instanceof Json ? Definition::file($old, ProjectRoot::origin(), $migrations) : $old)
        ->toEqual(Invalid::because(Problem::at('runnr', '`runnr` became `runner` in 2.0.0: run `mutation-gate migrate`')))
        ->and($current instanceof Json ? Definition::file($current, ProjectRoot::origin(), $migrations) : $current)
        ->toBeInstanceOf(Layer::class);
});
