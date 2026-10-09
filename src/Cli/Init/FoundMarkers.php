<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function count;

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * The chosen runner's own ignore markers in the trees zero-config found,
 * which `init` asks about (ADR-0017, decision 7); none where the trees or
 * the runner cannot be had, which the config `init` writes then says.
 */
final readonly class FoundMarkers
{
    public static function count(Extensions $extensions, Settings $settings): int
    {
        $chosen = new Chosen($extensions);
        $source = $chosen->treeSource($settings->treeSource());
        $trees = $source instanceof TreeSource ? $source->trees() : Trees::none();
        $runner = $chosen->runner($settings->runner()->choice());
        $paths = Paths::none();

        foreach ($trees instanceof Trees ? $trees : Trees::none() as $tree) {
            $paths = $paths->with($tree->path());
        }

        $markers = $runner instanceof Runner ? $runner->markers($paths) : Markers::none();

        return $markers instanceof Markers ? count($markers) : 0;
    }
}
