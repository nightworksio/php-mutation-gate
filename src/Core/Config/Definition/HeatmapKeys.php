<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `heatmap` keys a config writes are read into its `Reports` part (ADR-0002, ADR-0100): how much of the
 * heatmap each report shows, which reports and never judges.
 *
 * @internal
 */
final readonly class HeatmapKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $reports = Effect::JudgesOrReportsOnly;
        $files = Field::optional('files', Integer::atLeast(0), $reports);
        $comment = Field::optional('comment', Integer::atLeast(0), $reports);
        $methods = Field::optional('methods', Flag::boolean(), $reports);

        return [Field::section(
            'heatmap',
            Section::of(
                static function (Node $heatmap) use ($files, $comment, $methods): Layer|Invalid {
                    $lowest = $files->read($heatmap);
                    $fallen = $comment->read($heatmap);
                    $scored = $methods->read($heatmap);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Reports::heatmapping(HeatmapRows::of(
                            files: $lowest->value(),
                            comment: $fallen->value(),
                            methods: $scored->value(),
                        ))),
                        $lowest,
                        $fallen,
                        $scored,
                    );
                },
                $files,
                $comment,
                $methods,
            ),
        )];
    }
}
