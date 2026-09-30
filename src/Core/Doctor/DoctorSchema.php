<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Report\ReportSchema;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The JSON Schema of `doctor --format=json`, built from the same enums the
 * output is written from, and committed at `resources/doctor.schema.json`.
 */
final readonly class DoctorSchema
{
    public static function json(): string
    {
        $finding = [
            'type' => 'object',
            'properties' => [
                'slug' => ['enum' => array_map(static fn(Slug $slug): string => $slug->value, Slug::cases())],
                'severity' => [
                    'enum' => array_map(static fn(Severity $severity): string => $severity->value, Severity::cases()),
                ],
                'found' => ReportSchema::TEXT,
                'why' => ReportSchema::TEXT,
                'fix' => ReportSchema::TEXT,
                'seconds' => ['type' => 'number', 'minimum' => 0],
                'link' => ['type' => 'string', 'format' => 'uri'],
            ],
            'required' => ['slug', 'severity', 'found', 'why', 'fix', 'link'],
            'additionalProperties' => false,
        ];

        return JsonText::encode([
            '$schema' => ReportSchema::DRAFT,
            '$id' => sprintf(ReportSchema::ID, 'doctor'),
            'title' => 'mutation-gate doctor',
            'description' => 'What would fail, or run slowly, before a run does (ADR-0017, decision 9).',
            'type' => 'object',
            'properties' => [
                'format' => ['const' => DoctorJson::FORMAT],
                'failsARun' => ['type' => 'boolean'],
                'findings' => ['type' => 'array', 'items' => $finding],
            ],
            'required' => ['format', 'failsARun', 'findings'],
            'additionalProperties' => false,
        ]);
    }
}
