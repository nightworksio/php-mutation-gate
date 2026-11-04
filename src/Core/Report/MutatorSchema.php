<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * The JSON Schema of what `mutators --format=json` prints (ADR-0076), committed at
 * `resources/mutators.schema.json`.
 *
 * @internal
 */
final readonly class MutatorSchema
{
    public static function json(): string
    {
        $count = ReportSchema::WHOLE;
        $row = [
            'type' => 'object',
            'properties' => [
                'runner' => ReportSchema::TEXT,
                'mutator' => ReportSchema::TEXT,
                'mutants' => $count,
                'killed' => $count,
                'survived' => $count,
                'ignored' => $count,
                'seconds' => ReportSchema::SECONDS,
                'killRate' => ReportSchema::PERCENT,
                'secondsPerKill' => ReportSchema::SECONDS,
                'share' => ReportSchema::PERCENT,
            ],
            'required' => ['runner', 'mutator', 'mutants', 'killed', 'survived', 'ignored', 'seconds'],
            'additionalProperties' => false,
        ];

        return JsonText::encode([
            '$schema' => ReportSchema::DRAFT,
            '$id' => sprintf(ThisPackage::SCHEMA, 'mutators'),
            'title' => 'mutation-gate mutators report',
            'description' => 'What each mutator costs and finds, from a ledger\'s tally (ADR-0076).',
            'type' => 'object',
            'properties' => [
                'format' => ['const' => MutatorReport::FORMAT],
                'seconds' => ReportSchema::SECONDS,
                'mutators' => ['type' => 'array', 'items' => $row],
            ],
            'required' => ['format', 'seconds', 'mutators'],
            'additionalProperties' => false,
        ]);
    }
}
