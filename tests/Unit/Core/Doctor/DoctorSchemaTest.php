<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\DoctorSchema;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;

it('is committed as it is generated', function (): void {
    expect((string) file_get_contents(Schema::at('resources/doctor.schema.json')))
        ->toBe(sprintf("%s\n", DoctorSchema::json()), 'resources/doctor.schema.json is out of date. Run composer report:schema and commit it.');
});

it('closes every object, and allows only the slugs and severities there are', function (): void {
    $schema = DoctorSchema::json();

    expect(Decoded::at($schema))->toMatchArray(['type' => 'object', 'additionalProperties' => false])
        ->and(Decoded::at($schema, 'properties', 'findings', 'items', 'additionalProperties'))->toBeFalse()
        ->and(Decoded::at($schema, 'properties', 'findings', 'items', 'properties', 'slug', 'enum'))
        ->toBe(array_map(static fn(Slug $slug): string => $slug->value, Slug::cases()))
        ->and(Decoded::at($schema, 'properties', 'findings', 'items', 'properties', 'severity', 'enum'))
        ->toBe(['will-fail', 'slow', 'advice']);
});
