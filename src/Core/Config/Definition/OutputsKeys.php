<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_map;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
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
        $costReport = Field::optional('costReport', Flag::boolean(), $judges);
        $costliest = Field::optional('costliest', Integer::atLeast(1), $judges);

        return [Field::section(
            'outputs',
            Section::of(
                static function (Node $outputs) use (
                    $verdictOnly,
                    $automatic,
                    $actionReports,
                    $costs,
                    $costReport,
                    $costliest,
                ): Layer|Invalid {
                    $only = $verdictOnly->read($outputs);
                    $byRun = $automatic->read($outputs);
                    $forAction = $actionReports->read($outputs);
                    $said = $costs->read($outputs);
                    $where = $costReport->read($outputs);
                    $counted = $costliest->read($outputs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Reports::outputting(Outputs::of(
                            $only->value(),
                            self::named($byRun->value()),
                            self::named($forAction->value()),
                            $said->value(),
                            $where->value(),
                            $counted->value(),
                        ))),
                        $only,
                        $byRun,
                        $forAction,
                        $said,
                        $where,
                        $counted,
                    );
                },
                $verdictOnly,
                $automatic,
                $actionReports,
                $costs,
                $costReport,
                $costliest,
            ),
        )];
    }

    /**
     * @param  Listed<BuiltinReporter>|Absent $reporters
     * @return Listed<string>|Absent
     */
    private static function named(Listed|Absent $reporters): Listed|Absent
    {
        return $reporters instanceof Absent ? $reporters : Listed::of(...array_map(
            static fn(BuiltinReporter $reporter): string => $reporter->value,
            [...$reporters],
        ));
    }
}
