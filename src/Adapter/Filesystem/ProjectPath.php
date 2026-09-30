<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

/**
 * A path the gate is handed to write to or read from: from the project the
 * gate runs in, and kept inside it, or absolute, where the command line names
 * a file outside the project. Each is read and written through a `Directory`,
 * so a path that goes up out of the project, or a link that leads out of it,
 * is refused.
 */
final readonly class ProjectPath
{
    /** Where an absolute path is spelt from. */
    private const string FILE_SYSTEM = '/';

    private function __construct(private Path $path)
    {
    }

    public static function of(string $path): self
    {
        return new self(Path::of($path));
    }

    /** The path as it is named. */
    public function value(): string
    {
        return $this->path->value();
    }

    /** An entry under this path, as a directory. */
    public function child(string $name): self
    {
        return new self($this->path->child(Path::of($name)));
    }

    /** The directory this path is spelt from: the project, or the file system's root for an absolute one. */
    public function directory(): Directory
    {
        return $this->path->isAbsolute() ? Directory::at(self::FILE_SYSTEM) : Directory::in(Root::here());
    }

    /** This path, from that directory. */
    public function inside(): Path
    {
        return $this->path->isAbsolute() ? Path::of(mb_substr($this->path->value(), 1)) : $this->path;
    }
}
