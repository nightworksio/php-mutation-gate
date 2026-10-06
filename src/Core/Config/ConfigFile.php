<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function dirname;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

use function pathinfo;

/**
 * A config file in a project (ADR-0002): the file a loader reads, and the
 * origin of its paths, which it names from its own directory. Each is read as
 * the path from the project it lands on, wherever the file is, so every path
 * a config holds is the same on every machine; one that lands outside the
 * project, or is absolute, is refused.
 */
final readonly class ConfigFile implements PathOrigin
{
    private function __construct(private Path $file, private Path $directory, private Path $project)
    {
    }

    /** A config file, and the project whose paths it names; a file spelt from the project is inside it. */
    public static function at(Path $file, Path $project): self
    {
        $directory = dirname($file->value());

        return new self(
            $file,
            $file->isAbsolute() ? Path::of($directory) : ConfigPath::of($directory, $project->value())->path(),
            $project,
        );
    }

    /**
     * A copy of a config file, read in its place: the loader reads the copy,
     * and every path it holds is named from the directory of the file it is
     * a copy of, as when a run reads the config as it stood at a base.
     */
    public static function copyOf(Path $copy, Path $file, Path $project): self
    {
        $original = self::at($file, $project);

        return new self($copy, $original->directory, $project);
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** The file's extension, which names its format: `json`, `yml`. */
    public function extension(): string
    {
        return pathinfo($this->file->value(), PATHINFO_EXTENSION);
    }

    /**
     * A config this file holds, decoded from its format into JSON, read into its layer of config with every path
     * named from this file's directory, or every problem in it at once, each at its path. A config loader, the
     * gate's own or an extension's, reads every file through this.
     */
    public function read(Json $config): Layer|Invalid
    {
        return Definition::layer(Node::config($config->line()), $this);
    }

    public function reachesOutside(): bool
    {
        return false;
    }

    public function path(Path $written): Path
    {
        $landed = ConfigPath::of($written->value(), $this->directory->value())->path();

        return $written->isAbsolute() ? $written : $landed->from($this->project);
    }

    public function written(Path $path): string
    {
        $from = ConfigPath::of($path->value(), $this->project->value())->path();

        return $path->isAbsolute() ? $path->value() : $from->from($this->directory)->value();
    }
}
