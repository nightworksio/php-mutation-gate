<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reaching;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * What a run reaches: every unit for a full run, and otherwise what the
 * change since its base reaches, by the rules of ADR-0005, with the lines
 * each source file's change added or modified; or why those lines are not
 * known, for a full run or where git cannot tell what changed.
 */
final readonly class Reached
{
    private function __construct(private Reach $reach, private Changes|CannotTell $changed)
    {
    }

    /** Every unit, for this reason. */
    public static function everything(Trees $trees, CannotTell $why): self
    {
        return new self(Reach::nothing(Packages::of($trees))->everywhere(Reason::that($why->why())), $why);
    }

    public static function since(
        Revision $base,
        Trees $trees,
        Adapters $adapters,
        Settings $settings,
        Suite $suite,
        CoverageMap $map,
    ): self {
        $changes = $adapters->changes->changesSince($base);
        $reach = new Reaching(self::layout($adapters, $settings), $trees)->of(
            $changes,
            self::judges($adapters, $map),
            self::sources($changes, $base, $adapters, $suite->sources()),
        );

        return new self($reach, $changes instanceof Changes ? self::withLines($changes) : $changes);
    }

    /** The lines each source file's change since a base added or modified, or why git cannot tell. */
    public static function linesSince(Revision $base, Adapters $adapters): Changes|CannotTell
    {
        $changes = $adapters->changes->changesSince($base);

        return $changes instanceof Changes ? self::withLines($changes) : $changes;
    }

    public function reach(): Reach
    {
        return $this->reach;
    }

    /** The lines each source file's change added or modified, for the plan to hand the verdict. */
    public function changed(): Changes|CannotTell
    {
        return $this->changed;
    }

    private static function layout(Adapters $adapters, Settings $settings): Layout
    {
        $layout = Layout::standard($adapters->runner->definitions());

        foreach ($adapters->ci->definitions() as $definition) {
            $layout = $layout->runBy(Glob::of($definition->value()));
        }

        foreach ($settings->reach()->everything() as $glob) {
            $layout = $layout->decidedAlsoBy(Glob::of($glob));
        }

        return $layout;
    }

    /** Which test files judge each covered file, as the runner says. */
    private static function judges(Adapters $adapters, CoverageMap $map): Judges
    {
        $judges = Judges::none();

        foreach ($map->files() as $file) {
            $tests = $adapters->runner->judges($file, $map);
            $judges = $tests instanceof Paths ? $judges->judging($file, $tests) : $judges;
        }

        return $judges;
    }

    /** The test files on disk, and each changed file as it is on disk and as it was at the base. */
    private static function sources(
        Changes|CannotTell $changes,
        Revision $base,
        Adapters $adapters,
        Sources $sources,
    ): Sources {
        foreach ($changes instanceof Changes ? $changes : Changes::none() as $change) {
            $now = $adapters->changes->fileAt($change->path(), Revision::workingTree());
            $before = $adapters->changes->fileAt($change->previousPath(), $base);
            $sources = $now instanceof Contents ? $sources->withNow($change->path(), $now) : $sources;
            $sources = $before instanceof Contents ? $sources->withBefore($change->previousPath(), $before) : $sources;
        }

        return $sources;
    }

    private static function withLines(Changes $changes): Changes
    {
        $changed = Changes::none();

        foreach ($changes as $change) {
            $changed = $change->lines()->count() > 0
                ? $changed->with(Change::modified($change->path(), $change->lines()))
                : $changed;
        }

        return $changed;
    }
}
