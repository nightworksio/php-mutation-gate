<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrating;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\NotGiven;

function migratingRenames(): Migrations
{
    return Migrations::of(Migration::in('2.0.0', Rename::of('runnr', 'runner')));
}

it('moves a JSON config key by key, naming the current published schema, and says what it would write', function (): void {
    $older = (string) preg_replace('~/v[\d.]+/~', '/v0/', Definition::PUBLISHED);
    $migrated = Migrating::json('mutation-gate.json', sprintf("{\n    \"\$schema\": \"%s\",\n    \"runnr\": \"pest\"\n}\n", $older), migratingRenames());

    expect($migrated instanceof Migrated ? $migrated->after() : $migrated)
        ->toBe(sprintf("{\n    \"\$schema\": \"%s\",\n    \"runner\": \"pest\"\n}\n", Definition::PUBLISHED))
        ->and($migrated instanceof Migrated ? [$migrated->file(), $migrated->changes(), $migrated->note()] : [])
        ->toBe(['mutation-gate.json', true, ''])
        ->and($migrated instanceof Migrated ? $migrated->diff() : '')->toStartWith("--- a/mutation-gate.json\n");
});

it('moves a baseline key by key, leaving any $schema, and says a current file changes nothing', function (): void {
    $older = (string) preg_replace('~/v[\d.]+/~', '/v0/', Definition::PUBLISHED);
    $baseline = sprintf('{"$schema": "%s", "runnr": 1}', $older);
    $migrated = Migrating::baseline('mutation-gate.baseline.json', $baseline, migratingRenames());
    $current = Migrating::baseline('mutation-gate.baseline.json', '{"format": 1}', migratingRenames());

    expect($migrated instanceof Migrated ? $migrated->after() : $migrated)->toBe(sprintf('{"$schema": "%s", "runner": 1}', $older))
        ->and($current instanceof Migrated ? [$current->changes(), $current->diff(), $current->left()] : [])
        ->toEqual([false, '', NotGiven::value()]);
});

it('cannot migrate a file that holds no JSON object', function (): void {
    expect(Migrating::json('mutation-gate.json', '[1]', migratingRenames()))
        ->toEqual(CannotJudge::because('mutation-gate.json cannot be migrated: The file holds JSON, but not an object.'))
        ->and(Migrating::baseline('b.json', '{', migratingRenames()))->toEqual(CannotJudge::because('b.json cannot be migrated: Syntax error.'));
});

it('writes a file of another format again only where a change applies, noting that its comments are not kept', function (): void {
    $form = Json::parse('{"runnr": "pest"}');
    $written = static fn(Json $json): string => sprintf("# written\n%s", $json->line());
    $changed = $form instanceof Json ? Migrating::rewritten('mutation-gate.yaml', "runnr: pest # kept?\n", $form, migratingRenames(), $written) : $form;
    $current = Json::parse('{"runner": "pest"}');
    $untouched = $current instanceof Json ? Migrating::rewritten('mutation-gate.yaml', "runner: pest # kept\n", $current, migratingRenames(), $written) : $current;

    expect($changed instanceof Migrated ? [$changed->after(), $changed->note()] : $changed)->toBe([
        "# written\n{\"runner\":\"pest\"}",
        'mutation-gate.yaml is written again from its migrated form, so its comments are not kept.',
    ])
        ->and($untouched instanceof Migrated ? [$untouched->changes(), $untouched->after(), $untouched->note()] : $untouched)
        ->toBe([false, "runner: pest # kept\n", ''])
        ->and(Json::parse('[1]') instanceof Json ? Migrating::rewritten('m.yaml', '- 1', Json::parse('[1]'), migratingRenames(), $written) : null)
        ->toBeInstanceOf(CannotJudge::class);
});

it('leaves for a hand edit what the migrated file still writes', function (): void {
    $migrated = Migrating::json('mutation-gate.json', '{"runnr": "pest", "runner": "phpunit"}', migratingRenames());

    expect($migrated instanceof Migrated ? $migrated->left() : $migrated)->toBeInstanceOf(Invalid::class);
});
