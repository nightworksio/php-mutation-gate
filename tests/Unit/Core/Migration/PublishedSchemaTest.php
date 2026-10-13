<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\PublishedSchema;

function publishedSchemaOf(string $schema): string
{
    $document = JsonDocument::parse(sprintf('{"$schema": %s, "runner": "pest"}', json_encode($schema, JSON_UNESCAPED_SLASHES)));

    return $document instanceof JsonDocument ? PublishedSchema::current($document)->text() : '';
}

it('names the current release line\'s published schema where a config names another line\'s, and leaves any other $schema', function (): void {
    $current = sprintf('{"$schema": %s, "runner": "pest"}', json_encode(Definition::PUBLISHED, JSON_UNESCAPED_SLASHES));
    $older = (string) preg_replace('~/v[\d.]+/~', '/v0/', Definition::PUBLISHED);
    $local = 'vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json';

    expect(publishedSchemaOf($older))->toBe($current)
        ->and(publishedSchemaOf((string) preg_replace('~/v[\d.]+/~', '/v12/', Definition::PUBLISHED)))->toBe($current)
        ->and(publishedSchemaOf((string) preg_replace('~/v[\d.]+/~', '/v0.0/', Definition::PUBLISHED)))->toBe($current)
        ->and(publishedSchemaOf(Definition::PUBLISHED))->toBe($current)
        ->and(publishedSchemaOf($local))->toBe(sprintf('{"$schema": %s, "runner": "pest"}', json_encode($local, JSON_UNESCAPED_SLASHES)))
        ->and(publishedSchemaOf('https://example.com/v0/resources/mutation-gate.schema.json'))->toContain('example.com/v0')
        ->and(JsonDocument::parse('{"runner": "pest"}') instanceof JsonDocument
            ? PublishedSchema::current(JsonDocument::parse('{"runner": "pest"}'))->text()
            : '')->toBe('{"runner": "pest"}');
});

it('leaves the current release line\'s published schema as the file writes it, its slashes escaped or not', function (): void {
    $escaped = sprintf('{"$schema": %s}', json_encode(Definition::PUBLISHED));
    $document = JsonDocument::parse($escaped);

    expect($document instanceof JsonDocument ? PublishedSchema::current($document)->text() : $document)->toBe($escaped);
});
