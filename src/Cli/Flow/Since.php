<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_push;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;

use function sprintf;

/**
 * What changed since the commits a verdict's carried results were
 * established at, read from git and from the files on disk (ADR-0008,
 * decision 1). The files are read, and what each names looked up, once for
 * every commit; each commit's change is read once, however many results
 * share it.
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
        $php = $changes instanceof Changes ? $this->phpOf($changes) : Paths::none();
        $before = $this->adapters->changes->filesAt($php, $commit);
        $now = $this->adapters->changes->filesAt($php, Revision::workingTree());

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
            default => $this->graphOf(Suite::read($trees, $files, $this->adapters->project), $trees, $files),
        };

        return $read instanceof CannotJudge ? CannotJudge::because(sprintf(self::UNREAD, $read->why())) : $read;
    }

    /** @return array{NamedFiles, FileRoles}|CannotJudge */
    private function graphOf(Suite|CannotJudge $suite, Trees $trees, Fingerprints $all): array|CannotJudge
    {
        if ($suite instanceof CannotJudge) {
            return $suite;
        }

        $files = [];

        foreach ($suite->sources()->php() as $path => $php) {
            $files[$path->value()] = $php;
        }

        $outside = $this->phpOutside($suite->outside());
        $loaded = $this->loadedBy($all);

        return match (true) {
            $outside instanceof CannotJudge => $outside,
            $loaded instanceof CannotJudge => $loaded,
            default => [
                NamedFiles::read($files + $outside),
                FileRoles::of(Reached::layout($this->adapters, $this->settings, $suite), Packages::of($trees), $loaded),
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
            $directory = $file->path()->directory();

            if (! $file->path()->equals(Manifest::fileIn($directory))) {
                continue;
            }

            $contents = $this->adapters->project->read($file->path());
            $manifest = $contents instanceof Contents ? Manifest::decode($contents, $directory) : $contents;

            if ($manifest instanceof CannotJudge) {
                return $manifest;
            }

            array_push($loaded, ...($manifest instanceof Manifest ? $manifest->classLocations()->loadedFiles() : []));
        }

        return Paths::of(...$loaded);
    }

    /** @return array<string, PhpFile>|CannotJudge every PHP file outside the test directories, by its path */
    private function phpOutside(Fingerprints $outside): array|CannotJudge
    {
        $files = [];

        foreach ($outside as $file) {
            if (! $file->path()->isPhp()) {
                continue;
            }

            $contents = $this->adapters->project->read($file->path());

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $files[$file->path()->value()] = PhpFile::read($contents);
            }
        }

        return $files;
    }

    /** Every PHP file a change touched, as it is named now and as it was named before a rename. */
    private function phpOf(Changes $changes): Paths
    {
        $php = [];

        foreach ($changes as $change) {
            foreach ([$change->previousPath(), $change->path()] as $path) {
                if ($path->isPhp()) {
                    $php[$path->value()] = $path;
                }
            }
        }

        return Paths::of(...array_values($php));
    }
}
