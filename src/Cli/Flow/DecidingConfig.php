<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function basename;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Canonical;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The config file a run reads, as it decides how the gate runs
 * (ADR-0005): a change to it in its place decides nothing where every
 * setting but those that only judge or report, and any key no setting
 * declares, reads the same at the base as now, the command line laid over
 * both, and a change that moved only `paths` entries reaches the files they
 * match (ADR-0074). Its version at the base is read from a copy in the gate's
 * directory, and the copy is removed once read. Where either version
 * cannot be read, or the change renamed, moved, added or deleted the
 * file, it decides.
 *
 * @internal
 */
final readonly class DecidingConfig
{
    /** The directory the base's version of the config file is copied into to be read. */
    private const string BASE = '%s/base/%s';

    private function __construct(
        private Effective|Absent $effective,
        private CommandLine $given,
        private Root $project,
        private Path|Absent $file,
    ) {
    }

    /** The config file at this path from the project's root, read by a run given this command line. */
    public static function read(Effective $effective, CommandLine $given, string $project, Path $file): self
    {
        return new self($effective, $given, Root::of($project), $file);
    }

    /** A run that reads no config file, whose config no change leaves deciding alike. */
    public static function unread(): self
    {
        return new self(Absent::setting(), CommandLine::nothing(), Root::here(), Absent::setting());
    }

    /**
     * These sources, with the config file marked deciding alike where this
     * change to it in its place, from what it held at the base, moved no
     * setting that decides how the gate runs, and with the globs of each
     * `paths` entry it moved, whose files run otherwise (ADR-0074).
     */
    public function marking(Sources $sources, Change $change, Contents|Missing $before): Sources
    {
        $file = $this->file;
        $effective = $this->effective;

        $inPlace = $file instanceof Path
            && $effective instanceof Effective
            && $change->kind() === ChangeKind::Modified
            && $change->path()->equals($file)
            && $before instanceof Contents;

        return $inPlace ? $this->markedAlike($sources, $effective, $file, $before) : $sources;
    }

    /**
     * The file the baseline is committed in, as the config file decided it
     * holding what it held at the base: the file named now where the run reads
     * no config file, and the standard one where the base held no config file.
     */
    public function baselineAt(Contents|Missing $before, Path $now): Path|Invalid|CannotJudge
    {
        $file = $this->file;
        $effective = $this->effective;

        if (! $file instanceof Path || ! $effective instanceof Effective) {
            return $now;
        }

        if (! $before instanceof Contents) {
            return Floors::none()->baseline();
        }

        $then = $this->atBase($effective, $file, $before);

        return $then instanceof Settings ? $then->floors()->baseline() : $then;
    }

    /**
     * These sources, with the config file marked deciding alike where, holding what it held at the base, it
     * decides as now, and with the globs of each `paths` entry the change moved.
     */
    private function markedAlike(Sources $sources, Effective $effective, Path $named, Contents $before): Sources
    {
        $then = $this->atBase($effective, $named, $before);
        $now = $effective->settings($this->given);

        if (! $then instanceof Settings || ! $now instanceof Settings) {
            return $sources;
        }

        $moved = $now->effective()->setup()->paths()->movedSince($then->effective()->setup()->paths());
        $alike = $sources->decidingAlike($named);

        return match (true) {
            Canonical::decidingIn($then) !== Canonical::decidingIn($now) => $sources,
            [...$moved] === [] => $alike,
            default => $alike->movingRules($named, ...$moved),
        };
    }

    /** The settings, where the config file holds what it held at the base, read from a copy in the gate's directory. */
    private function atBase(Effective $effective, Path $named, Contents $before): Settings|Invalid|CannotJudge
    {
        $directory = Directory::at($this->project->value());
        $copy = Path::of(sprintf(self::BASE, GateDirectory::root()->value(), basename($named->value())));
        $written = $directory->write($copy, $before);
        $read = ConfigFile::copyOf($this->onDisk($copy), $this->onDisk($named), Path::of($this->project->value()))
            ->startedAs(StartedGate::in($this->project->value()));
        $then = $written instanceof Written ? $effective->settingsOf($this->given, $read) : $written;
        $directory->remove($copy);

        return $then;
    }

    /** Where a path of the project is on disk. */
    private function onDisk(Path $path): Path
    {
        return Path::of($this->project->at($path)->value());
    }
}
