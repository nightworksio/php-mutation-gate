<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Definition\ContextsKeys;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('reads a block for each context, each as a layer reads its keys, and keeps only the blocks written', function (): void {
    $layer = Configs::layer(['contexts' => [
        'main' => ['shards' => ['max' => 16]],
        'pr' => ['budget' => '10m', 'trees' => [['path' => 'src', 'floor' => 90]]],
    ]]);

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))->toBe(['contexts' => [
        'pr' => ['trees' => [['path' => 'src', 'floor' => 90]], 'budget' => '10m'],
        'main' => ['shards' => ['max' => 16]],
    ]]);
});

it('refuses in a block what is read before the context is known, at its path', function (): void {
    $refused = 'expected nothing: a context block cannot set what is read before the context is known';

    expect(Configs::problems(Configs::layer(['contexts' => [
        'pr' => ['preset' => 'laravel', 'extensions' => ['Acme\\Gate']],
        'main' => ['ci' => ['plan' => 'github', 'defaultBranch' => 'trunk', 'check' => 'gate']],
    ]])))->toBe([
        sprintf('contexts.pr.extensions: %s', $refused),
        sprintf('contexts.pr.preset: %s', $refused),
        sprintf('contexts.main.ci.plan: %s', $refused),
        sprintf('contexts.main.ci.defaultBranch: %s', $refused),
    ])->and(Configs::layer(['preset' => 'laravel', 'ci' => ['plan' => 'github', 'defaultBranch' => 'trunk']]))
        ->toBeInstanceOf(Layer::class);
});

it('refuses a context it does not know, a block in a block and a block that is no object', function (): void {
    expect(Configs::problems(Configs::layer(['contexts' => [
        'prr' => ['budget' => '5m'],
        'ci' => ['contexts' => ['pr' => []]],
        'local' => 'fast',
    ]])))->toBe([
        'contexts.local: expected an object, got "fast"',
        'contexts.ci.contexts: unknown key',
        'contexts.prr: unknown key, did you mean pr?',
    ]);
});

it('names a block\'s keys once in the schema, under $defs, each context referring to them', function (): void {
    $schema = Definition::schema();
    $block = static fn(string ...$keys): mixed => Decoded::at($schema, '$defs', ContextsKeys::BLOCK, 'properties', ...$keys);
    $contexts = Decoded::at($schema, 'properties', 'contexts', 'properties');

    expect(is_array($contexts) ? array_keys($contexts) : $contexts)->toBe(['local', 'ci', 'github', 'gitlab', 'bitbucket', 'azure', 'buildkite', 'jenkins', 'circleci', 'pr', 'main'])
        ->and(Decoded::at($schema, 'properties', 'contexts', 'properties', 'pr'))->toBe(['$ref' => '#/$defs/contextBlock'])
        ->and(Decoded::at($schema, 'properties', 'contexts', 'additionalProperties'))->toBeFalse()
        ->and($block('contexts'))->toBeNull()
        ->and($block('preset', 'not'))->toBe([])
        ->and($block('ci', 'properties', 'plan', 'not'))->toBe([])
        ->and($block('ci', 'properties', 'check', 'not'))->toBeNull()
        ->and($block('budget'))->toBe(Decoded::at($schema, 'properties', 'budget'));
});

it('is an object of blocks, whose settings it leaves to the layer a run lays', function (): void {
    $shape = ContextsKeys::shape(Definition::block(ProjectRoot::origin()));

    expect($shape->expected())->toBe('an object')
        ->and($shape->effects())->toBe([])
        ->and(Configs::problems(Configs::layer(['contexts' => ['ci' => ['budget' => 5]]])))
        ->toBe(['contexts.ci.budget: expected a duration such as 90s, 15m, 1h30m or 7d, got 5']);
});
