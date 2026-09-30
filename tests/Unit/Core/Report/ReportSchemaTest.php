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
        ->toBe(['format', 'judgement', 'cutShort', 'uncovered', 'counts', 'trees', 'newCode', 'mutants', 'reach', 'warnings', 'failures'])
        ->and(Decoded::at($schema, 'properties', 'mutants', 'items', 'required'))
        ->toBe(['id', 'file', 'line', 'mutator', 'family', 'diff', 'status', 'judgement', 'changedLine', 'tests', 'hint', 'reproduce'])
        ->and(Decoded::at($schema, 'properties', 'trees', 'items', 'properties', 'units', 'items', 'required'))->toBe(['path', 'origin']);
});
