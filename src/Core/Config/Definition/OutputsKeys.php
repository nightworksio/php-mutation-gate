<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Outputs;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the keys a config writes are read into `outputs` (ADR-0112).
 *
 * @internal
 */
final readonly class OutputsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $named = static fn(BuiltinReporter $reporter): string => $reporter->value;
        $verdictOnly = Field::optional('verdictOnly', Flag::boolean(), $judges);
        $automatic = Field::optional(
            'automatic',
            Items::distinct(
                Enumerated::of([
                    BuiltinReporter::GitHubAnnotations,
                    BuiltinReporter::GitHubSummary,
                    BuiltinReporter::GitHubComment,
                    BuiltinReporter::Badge,
                ]),
                $named,
            ),
            $judges,
        );
        $actionReports = Field::optional(
            'actionReports',
            Items::distinct(
                Enumerated::of([BuiltinReporter::Json, BuiltinReporter::Html, BuiltinReporter::Digest]),
                $named,
            ),
            $judges,
        );
        $costs = Field::optional('costs', Flag::boolean(), $judges);

        return [Field::section(
            'outputs',
            Section::of(
                static function (Node $outputs) use ($verdictOnly, $automatic, $actionReports, $costs): Layer|Invalid {
                    $only = $verdictOnly->read($outputs);
                    $byRun = $automatic->read($outputs);
                    $forAction = $actionReports->read($outputs);
                    $said = $costs->read($outputs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Reports::outputting(Outputs::of(
                            $only->value(),
                            $byRun->value(),
                            $forAction->value(),
                            $said->value(),
                        ))),
                        $only,
                        $byRun,
                        $forAction,
                        $said,
                    );
                },
                $verdictOnly,
                $automatic,
                $actionReports,
                $costs,
            ),
        )];
    }
}
