<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * A shallow clone, which holds only the newest commits: git cannot say what
 * changed since an older one (ADR-0005, decision 3; ADR-0008, decision 1).
 */
final readonly class ShallowClone
{
    private const string FOUND = 'The clone is shallow: it holds only the newest commits.';

    private const string WHY = <<<'WHY'
        Git cannot say what changed since a commit the clone does not hold, so a change-scoped run from an older base
        mutates everything, and no kill carries from an older commit for a unit a time budget never started.
        WHY;

    private const string FIX = 'Clone the whole history, as `fetch-depth: 0` asks of `actions/checkout`.';

    public static function in(Observations $observed): Findings
    {
        return $observed->files()->isShallow()
            ? Findings::of(Finding::of(Slug::ShallowClone, Severity::Slow, self::FOUND, self::WHY, self::FIX))
            : Findings::none();
    }
}
