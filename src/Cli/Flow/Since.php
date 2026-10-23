<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_push;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;

use function sprintf;

/**
 * What changed since the commits a verdict's carried results were
 * established at, read from git and from the files on disk (ADR-0008). The
 * files are read, and what each names looked up, once for every commit;
 * each commit's change is read once, however many results share it.
 */
final readonly class Since
{
    private const string UNREAD = 'The files on disk cannot be read to follow what changed by name. %s';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** What changed since each of these commits, or why git cannot say. */
    public function of(Revision ...$commits): ChangesSince
    {
        $since = ChangesSince::none();

        if ($commits === []) {
            return $since;
        }

        $read = $this->read();

        foreach ($commits as $commit) {
            $since = $since->with(
                $commit,
                $read instanceof CannotJudge ? CannotTell::because($read->why()) : $this->reach($commit, ...$read),
            );
        }

        return $since;
    }

    /** What the change since a commit reaches of the files on disk, or why git cannot say. */
    private function reach(Revision $commit, NamedFiles $graph, FileRoles $roles): ChangeReach|CannotTell
    {
        $changes = $this->adapters->changes->changesFrom($commit);
        $read = $changes instanceof Changes ? $this->readOf($changes, $roles) : Paths::none();
        $before = $this->adapters->changes->filesAt($read, $commit);
        $now = $this->adapters->changes->filesAt($read, Revision::workingTree());

        return match (true) {
            $changes instanceof CannotTell => $changes,
            $before instanceof CannotTell => $before,
            $now instanceof CannotTell => $now,
            default => ChangeReach::of($changes, $before, $now, $graph, $roles),
        };
    }

    /**
     * What every PHP file on disk names, and what each file is to a carried
     * kill; or why they cannot be read.
     *
     * @return array{NamedFiles, FileRoles}|CannotJudge
     */
    private function read(): array|CannotJudge
    {
        $trees = $this->adapters->trees->trees();
        $files = $this->adapters->changes->fingerprints();
        $read = match (true) {
            $trees instanceof CannotJudge => $trees,
            $files instanceof CannotTell => CannotJudge::because($files->why()),
            default => $this->graphOf(
                Suite::read($trees, $files, $this->adapters->project, $this->adapters->suite),
                $trees,
                $files,
            ),
        };

        return $read instanceof CannotJudge ? CannotJudge::because(sprintf(self::UNREAD, $read->why())) : $read;
    }

    /** @return array{NamedFiles, FileRoles}|CannotJudge */
    private function graphOf(Suite|CannotJudge $suite, Trees $trees, Fingerprints $all): array|CannotJudge
    {
        if ($suite instanceof CannotJudge) {
            return $suite;
        }

        $named = new NameGraph($this->adapters)->of($suite);
        $loaded = $this->loadedBy($all);

        return match (true) {
            $named instanceof CannotJudge => $named,
            $loaded instanceof CannotJudge => $loaded,
            default => [
                $named,
                FileRoles::of(
                    Reached::layout($this->adapters, $this->settings, $suite),
                    Packages::of($trees),
                    $loaded,
                    $this->settings->reach()->lockPackages(),
                ),
            ],
        };
    }

    /**
     * Every file a `composer.json` among these files has Composer's
     * autoloader load in every process; or why one cannot be read.
     */
    private function loadedBy(Fingerprints $files): Paths|CannotJudge
    {
        $loaded = [];

        foreach ($files as $file) {
            $loads = $this->loadedFrom($file->path());

            if ($loads instanceof CannotJudge) {
                return $loads;
            }

            array_push($loaded, ...$loads);
        }

        return Paths::of(...$loaded);
    }

    /**
     * Every file a file has Composer's autoloader load in every process,
     * where it is a `composer.json`; or why it cannot be read.
     */
    private function loadedFrom(Path $file): Paths|CannotJudge
    {
        $directory = $file->directory();

        if (! $file->equals(Manifest::fileIn($directory))) {
            return Paths::none();
        }

        $contents = $this->adapters->project->read($file);
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, $directory) : $contents;

        return match (true) {
            $manifest instanceof Manifest => $manifest->classLocations()->loadedFiles(),
            $manifest instanceof CannotJudge => $manifest,
            default => Paths::none(),
        };
    }

    /** Each PHP file and each package's lock these changes touch, as it is and as it was: the files read both ways. */
    private function readOf(Changes $changes, FileRoles $roles): Paths
    {
        $read = [];

        foreach ($changes as $change) {
            foreach ([$change->previousPath(), $change->path()] as $path) {
                if ($path->isPhp() || $roles->isLock($path)) {
                    $read[] = $path;
                }
            }
        }

        return Paths::of(...$read);
    }
}
