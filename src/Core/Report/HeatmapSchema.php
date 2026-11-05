<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/**
 * The JSON Schema of the heatmap the JSON report and `summary.json` hold
 * (ADR-0084), as {@see HeatmapJson} writes it.
 *
 * @internal
 *
 * @phpstan-import-type Flat from ReportSchema
 * @phpstan-import-type Rows from ReportSchema
 * @phpstan-import-type Grouped from ReportSchema
 */
final readonly class HeatmapSchema
{
    /** What a file or a function holds besides what names it: its score, its base and how many are not killed. */
    private const array SCORED = [
        'score' => ReportSchema::PERCENT,
        'base' => ReportSchema::PERCENT,
        'notKilled' => ReportSchema::WHOLE,
    ];

    private const string NOT_KILLED = 'notKilled';

    /** @return array{type: string, items: Grouped} the JSON report's `heatmap`: every file, each with its functions */
    public static function report(): array
    {
        $function = [
            'type' => 'object',
            'properties' => ['name' => ReportSchema::TEXT, ...self::SCORED],
            'required' => ['name', self::NOT_KILLED],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'path' => ReportSchema::TEXT,
                    'functions' => ['type' => 'array', 'items' => $function],
                    ...self::SCORED,
                ],
                'required' => ['path', 'functions', self::NOT_KILLED],
                'additionalProperties' => false,
            ],
        ];
    }

    /** @return Rows `summary.json`'s `heatmap`: the lowest-scoring files */
    public static function summary(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['path' => ReportSchema::TEXT, ...self::SCORED],
                'required' => ['path', self::NOT_KILLED],
                'additionalProperties' => false,
            ],
        ];
    }
}
