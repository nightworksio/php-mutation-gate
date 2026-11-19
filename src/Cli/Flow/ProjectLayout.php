<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Modules;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * Where a project keeps what the reach rules ask about (ADR-0005): the files
 * that decide how the gate runs, the CI definitions that run it, the tests,
 * the fixture projects `reach.fixtures` names and the repository's modules.
 *
 * @internal
 */
final readonly class ProjectLayout
{
    /**
     * Where the repository keeps what the reach rules ask about: the files
     * that decide how the gate runs, among them what `reach.everything` and
     * the presets name and what the config file reads beside itself, the CI
     * definitions that run it, the tests, and the fixture projects
     * `reach.fixtures` names.
     */
    public static function of(Adapters $adapters, Settings $settings, Suite $suite): Layout
    {
        $layout = Layout::standard($adapters->runner->definitions())
            ->testedIn(...$suite->directories())
            ->supportedFrom(...$suite->supportPlaces())
            ->readByTheConfig($adapters->configReads)
            ->withFixtures(...$settings->reach()->fixtures());

        foreach ($adapters->ci->definitions() as $definition) {
            $layout = $layout->runBy(Glob::of($definition->value()));
        }

        $judged = JudgedRun::of($adapters->project, OwnArchive::running());

        foreach ($settings->reach()->everything() as $glob) {
            $layout = $judged->leavesOut($glob) ? $layout : $layout->decidedAlsoBy($glob);
        }

        foreach (self::modules($adapters) as $module) {
            $layout = $layout->withModule($module);
        }

        return $layout;
    }

    /** The repository's modules among the files version control lists; none where it lists none or reads no tree. */
    private static function modules(Adapters $adapters): Paths
    {
        $listed = $adapters->changes->listed(Paths::of(Path::root()));
        $trees = $adapters->trees->trees();

        return $listed instanceof Paths && $trees instanceof Trees
            ? Modules::among($listed, Packages::of($trees)->paths())
            : Paths::none();
    }
}
