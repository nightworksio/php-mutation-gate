<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * What a full run is estimated to take in one job where nothing was
 * measured: each tree's lines of code at `costs.secondsPerLine` (ADR-0006,
 * decision 4; ADR-0017, decision 4).
 */
final readonly class LineCountEstimate
{
    private const string NO_SOURCE = 'the tree source cannot be built from its options';

    /** The estimate for the trees the settings' tree source finds, or why there is none. */
    public static function of(Extensions $extensions, string $project, Settings $settings): Seconds|CannotJudge
    {
        $source = new Chosen($extensions)->treeSource($settings->treeSource());
        $trees = match (true) {
            $source instanceof Invalid => CannotJudge::because(self::NO_SOURCE),
            $source instanceof CannotJudge => $source,
            default => $source->trees(),
        };

        if (! $trees instanceof Trees) {
            return $trees;
        }

        $costs = MeasuredCosts::at(Root::of($project), $settings->shards()->secondsPerLine());
        $seconds = 0.0;

        foreach ($trees as $tree) {
            $estimated = $costs->cost(Unit::file($tree->path()), Timings::none(), FirstRun::unmeasured());
            $seconds += $estimated->seconds()->seconds();
        }

        return Seconds::of($seconds);
    }
}
