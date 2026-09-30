<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function dirname;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Path;

use function pathinfo;
use function sprintf;
use function str_starts_with;

/**
 * A config file in a project (ADR-0002): the file a loader reads, and the
 * origin of its paths, which it names from its own directory. Where the
 * project itself is stays out of what is read, so every path a config holds
 * is the same on every machine.
 */
final readonly class ConfigFile implements Origin
{
    /** @param string $directory the file's directory from the project; '' for the project, absolute outside it */
    private function __construct(private Path $file, private string $directory)
    {
    }

    /** A config file, and the project whose paths it names. */
    public static function at(Path $file, Path $project): self
    {
        $directory = dirname($file->value());
        $root = $project->value();

        return new self(
            $file,
            match (true) {
                $directory === $root, $directory === '.' => '',
                str_starts_with($directory, sprintf('%s/', $root)) => mb_substr($directory, mb_strlen($root) + 1),
                default => $directory,
            },
        );
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

    public function path(string $written): Path
    {
        return ConfigPath::of($written, $this->directory)->path();
    }

    public function written(Path $path): string
    {
        return ConfigPath::from($path, $this->directory);
    }
}
