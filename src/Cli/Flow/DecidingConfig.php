<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function basename;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The config file a run reads, as it decides how the gate runs (ADR-0005,
 * decision 4): a change to it decides nothing where every setting that
 * affects results (ADR-0007) reads the same at the base as now, the command
 * line laid over both. Its version at the base is read from a copy in the
 * gate's directory, in the file's place, and the copy is removed once read.
 * Where either version cannot be read, it decides.
 */
final readonly class DecidingConfig
{
    /** The directory the base's version of the config file is copied into to be read. */
    private const string BASE = '%s/base/%s';

    private function __construct(
        private Effective|Absent $effective,
        private CommandLine $given,
        private string $project,
        private Path|Absent $file,
    ) {
    }

    /** The config file at this path from the project's root, read by a run given this command line. */
    public static function read(Effective $effective, CommandLine $given, string $project, Path $file): self
    {
        return new self($effective, $given, $project, $file);
    }

    /** A run that reads no config file, whose config no change leaves deciding alike. */
    public static function unread(): self
    {
        return new self(Absent::setting(), CommandLine::nothing(), '', Absent::setting());
    }

    /**
     * These sources, with the config file marked deciding alike, under its
     * name and the one it had at the base, where this change to it, from
     * what it held at the base, moved no setting that affects results.
     */
    public function marking(Sources $sources, Change $change, Contents|Missing $before): Sources
    {
        $file = $this->file;
        $effective = $this->effective;

        $alike = $file instanceof Path
            && $effective instanceof Effective
            && $change->path()->equals($file)
            && $change->kind() !== ChangeKind::Deleted
            && $before instanceof Contents
            && $this->decidesAlike($effective, $change->previousPath(), $before);

        return $alike ? $sources->decidingAlike($change->path())->decidingAlike($change->previousPath()) : $sources;
    }

    /** Whether the config file, holding what it held at the base, under the name it had there, decides as now. */
    private function decidesAlike(Effective $effective, Path $named, Contents $before): bool
    {
        $directory = Directory::at($this->project);
        $copy = Path::of(sprintf(self::BASE, GateDirectory::root()->value(), basename($named->value())));
        $written = $directory->write($copy, $before);
        $read = ConfigFile::copyOf($this->onDisk($copy), $this->onDisk($named), Path::of($this->project));
        $then = $written instanceof Written ? $effective->canonicalOf($this->given, $read) : $written;
        $directory->remove($copy);
        $now = $effective->settings($this->given);

        return is_string($then) && $now instanceof Settings && $then === $now->canonical();
    }

    /** Where a path of the project is on disk. */
    private function onDisk(Path $path): Path
    {
        return Path::of(sprintf('%s/%s', $this->project, $path->value()));
    }
}
