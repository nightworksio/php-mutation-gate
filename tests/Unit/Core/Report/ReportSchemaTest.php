<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\ReportSchema;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;

it('is committed as it is generated', function (): void {
    expect((string) file_get_contents(Schema::at('resources/report.schema.json')))
        ->toBe(sprintf("%s\n", ReportSchema::json()), 'resources/report.schema.json is out of date. Run composer report:schema and commit it.');
});

it('holds every property of every object to a closed list', function (): void {
    $schema = ReportSchema::json();

    expect(Decoded::at($schema))->toMatchArray(['type' => 'object', 'additionalProperties' => false])
        ->and(Decoded::at($schema, 'required'))
        ->toBe(['format', 'judgement', 'cutShort', 'uncovered', 'counts', 'trees', 'newCode', 'security', 'suites', 'matrix', 'tests', 'mutants', 'clusters', 'reach', 'warnings', 'failures', 'cannotJudge'])
        ->and(Decoded::at($schema, 'properties', 'mutants', 'items', 'required'))
        ->toBe(['id', 'file', 'line', 'mutator', 'status', 'judgement', 'changedLine', 'tests', 'coveredBy', 'killedBy', 'hint', 'reproduce', 'explain'])
        ->and(Decoded::at($schema, 'properties', 'trees', 'items', 'properties', 'units', 'items', 'required'))->toBe(['path', 'origin'])
        ->and(Decoded::at($schema, 'properties', 'suites', 'items', 'required'))->toBe(['name', 'covered', 'killed', 'exact']);
});

it('commits explain\'s schema as it is generated, every object held to a closed list', function (): void {
    $schema = ReportSchema::explain();
    $explained = ['properties', 'mutants', 'items'];

    expect((string) file_get_contents(Schema::at('resources/explain.schema.json')))
        ->toBe(sprintf("%s\n", $schema), 'resources/explain.schema.json is out of date. Run composer report:schema and commit it.')
        ->and(Decoded::at($schema))->toMatchArray(['type' => 'object', 'additionalProperties' => false])
        ->and(Decoded::at($schema, 'required'))->toBe(['format', 'mutants'])
        ->and(Decoded::at($schema, ...[...$explained, 'required']))->toBe(['mutant', 'tests', 'unit', 'history'])
        ->and(Decoded::at($schema, ...[...$explained, 'properties', 'tests', 'items', 'required']))->toBe(['id', 'name', 'outcome'])
        ->and(Decoded::at($schema, ...[...$explained, 'properties', 'unit', 'oneOf', '0', 'required']))->toBe(['path', 'origin', 'reach'])
        ->and(Decoded::at($schema, ...[...$explained, 'properties', 'history', 'items', 'required']))->toBe(['status', 'run', 'scope', 'at']);
});
