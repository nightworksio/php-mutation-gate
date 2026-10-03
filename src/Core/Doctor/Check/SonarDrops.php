<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A tree outside `sonar.sources`, while a `sonar` report is written:
 * SonarQube drops an issue on a file it did not index, and says only how
 * many it dropped (ADR-0028, decision 10).
 */
final readonly class SonarDrops
{
    private const string FOUND = 'Sonar drops the survivors in `%s`, which is outside `sonar.sources`.';

    private const string WHY
        = 'SonarQube indexes only the files under sonar.sources, and drops an imported issue on any other file.';

    private const string FIX = 'Add %s to sonar.sources in sonar-project.properties, or leave it out of the trees.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $trees = $observed->trees();
        $sources = $observed->files()->sonarSources();

        return $settings instanceof Settings && self::reportsTo($settings) && $trees instanceof Trees
            && $sources instanceof SonarSources
            ? self::outside($trees, $sources)
            : Findings::none();
    }

    /** Whether the config lists a `sonar` report. */
    private static function reportsTo(Settings $settings): bool
    {
        foreach ($settings->reports() as $report) {
            $use = $report->reporter()->use();

            if ($use instanceof Name && $use->value() === BuiltinReporter::Sonar->value) {
                return true;
            }
        }

        return false;
    }

    private static function outside(Trees $trees, SonarSources $sources): Findings
    {
        $findings = Findings::none();

        foreach ($trees as $tree) {
            $path = $tree->path()->value();
            $findings = $sources->holds($tree->path()) ? $findings : $findings->and(Findings::of(Finding::of(
                Slug::OutsideSonarSources,
                Severity::Advice,
                sprintf(self::FOUND, $path),
                self::WHY,
                sprintf(self::FIX, $path),
            )));
        }

        return $findings;
    }
}
