<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_merge;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Affecting;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\ReadingTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SourceTests;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;

use function sprintf;

/**
 * ADR-0020's rules, given what they read: the layout and the trees, the
 * suite's files and each changed file as it is and as it was, which test
 * files the runner says judge each changed file, the map, and the scan of
 * the suite, the files the map covers and the changed source files.
 */
final readonly class AffectedRules
{
    private const string UNJUDGED = 'The runner cannot say which tests judge `%s`. %s So every test is listed.';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Inventory $inventory,
        private CoverageMap $map,
    ) {
    }

    /** The tests these changes reach, over a suite whose test files hold the map's tests so. */
    public function of(AskedChanges $asked, TestPlaces $places): AffectedTests
    {
        $judges = $this->judgesOf($asked);

        if ($judges instanceof Reason) {
            return AffectedTests::every($places, $judges);
        }

        $layout = Reached::layout($this->adapters, $this->settings, $this->inventory->suite);
        $sources = $this->sourcesOf($asked);
        $users = SupportUsers::in($layout, Packages::of($this->inventory->trees), $sources);
        $readers = new ReadingTests($places, $this->map, $this->codebaseOf($asked), $users, $sources);

        return new Affecting(
            $layout,
            $this->inventory->trees,
            Globs::of(...$this->settings->proofs()->ignore()),
            new SourceTests($places, $judges, $this->map, $readers, $users, $sources),
            $users,
        )->of($asked->changes(), $sources, $places);
    }

    /** Which test files the runner says judge each changed file, as it is and as it was; or why that cannot be told. */
    private function judgesOf(AskedChanges $asked): Judges|Reason
    {
        $judges = Judges::none();

        foreach ($asked->changes() as $change) {
            foreach (Paths::of($change->previousPath(), $change->path()) as $file) {
                $tests = $this->adapters->runner->judges($file, $this->map);

                if ($tests instanceof CannotJudge) {
                    return Reason::that(sprintf(self::UNJUDGED, $file->value(), $tests->why()));
                }

                $judges = $judges->judging($file, $tests);
            }
        }

        return $judges;
    }

    /** The suite's files on disk, and each changed file as it is and as it was at the revision it changed since. */
    private function sourcesOf(AskedChanges $asked): Sources
    {
        $sources = $this->inventory->suite->sources();

        foreach ($asked->since() as [$change, $since]) {
            $now = $this->adapters->changes->fileAt($change->path(), Revision::workingTree());
            $before = $this->adapters->changes->fileAt($change->previousPath(), $since);
            $sources = $now instanceof Contents ? $sources->withNow($change->path(), $now) : $sources;
            $sources = $before instanceof Contents ? $sources->withBefore($change->previousPath(), $before) : $sources;
        }

        return $sources;
    }

    /** The scan of the suite's PHP files, the files the map covers and the changed source files, as they are. */
    private function codebaseOf(AskedChanges $asked): Codebase
    {
        $read = [];
        $suite = $this->inventory->suite->sources();

        foreach ($this->inventory->suite->files() as $file) {
            $path = $file->fingerprint()->path();
            $contents = $suite->now($path);
            $read[$path->value()] = $contents instanceof Contents ? [Source::read($path, $contents, test: true)] : [];
        }

        foreach ([...$this->map->files(), ...$this->changedPhp($asked)] as $path) {
            $read[$path->value()] ??= $this->sourceAt($path);
        }

        return Codebase::of(...array_merge(...array_values($read)));
    }

    /** @return list<Path> */
    private function changedPhp(AskedChanges $asked): array
    {
        $paths = [];

        foreach ($asked->changes() as $change) {
            $paths = $change->path()->isPhp() ? [...$paths, $change->path()] : $paths;
        }

        return $paths;
    }

    /** @return list<Source> the source file at a path, read; none where it cannot be */
    private function sourceAt(Path $path): array
    {
        $contents = $this->adapters->project->read($path);

        return $contents instanceof Contents ? [Source::read($path, $contents, test: false)] : [];
    }
}
