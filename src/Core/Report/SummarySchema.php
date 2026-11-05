<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

use function sprintf;

/**
 * The JSON Schema of `summary.json`, what an organisation's dashboard reads
 * of a repository (ADR-0024), committed at `resources/summary.schema.json`.
 *
 * @internal
 */
final readonly class SummarySchema
{
    public static function json(): string
    {
        $tree = [
            'type' => 'object',
            'properties' => [
                'path' => ReportSchema::TEXT,
                'floor' => ReportSchema::PERCENT,
                'score' => ReportSchema::PERCENT,
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];

        return JsonText::encode([
            '$schema' => ReportSchema::DRAFT,
            '$id' => sprintf(ThisPackage::SCHEMA, 'summary'),
            'title' => 'mutation-gate summary',
            'description' => 'What an organisation\'s dashboard reads of a repository\'s latest verdict (ADR-0024).',
            'type' => 'object',
            'properties' => [
                'format' => ['const' => Summary::FORMAT],
                'commit' => ReportSchema::TEXT,
                'time' => ReportSchema::INSTANT,
                'verdict' => ['enum' => [
                    Judgement::Passed->value,
                    Judgement::Failed->value,
                    Judgement::CannotJudge->value,
                ]],
                'score' => ReportSchema::PERCENT,
                'trees' => ['type' => 'array', 'items' => $tree],
                'newCode' => [
                    'type' => 'object',
                    'properties' => ['floor' => ReportSchema::PERCENT],
                    'required' => ['floor'],
                    'additionalProperties' => false,
                ],
                'savings' => [
                    'type' => 'object',
                    'properties' => ['last30Days' => ReportSchema::SECONDS],
                    'required' => ['last30Days'],
                    'additionalProperties' => false,
                ],
                'mutators' => [
                    'type' => 'array',
                    'maxItems' => MutatorReport::COSTLIEST,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'runner' => ReportSchema::TEXT,
                            'mutator' => ReportSchema::TEXT,
                            'seconds' => ReportSchema::SECONDS,
                            'survived' => ReportSchema::WHOLE,
                            'killRate' => ReportSchema::PERCENT,
                        ],
                        'required' => ['runner', 'mutator', 'seconds', 'survived'],
                        'additionalProperties' => false,
                    ],
                ],
                'heatmap' => HeatmapSchema::summary(),
            ],
            'required' => ['format', 'commit', 'time', 'verdict', 'trees', 'newCode'],
            'additionalProperties' => false,
        ]);
    }
}
