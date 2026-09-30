<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Reports;

/** How the keys a config writes are read into its `Reports` part (ADR-0002). */
final readonly class ReportsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        return [Field::optional(
            'reports',
            Into::of(
                Items::of(ReportEntry::choosing(Builtins::reporters(), $origin)),
                static fn(Listed $reports): Layer => Layer::of(Reports::of($reports)),
            ),
            Effect::JudgesOrReportsOnly,
        )];
    }
}
