<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The files a config file reads beside itself, as its loader finds them
 * without running it (ADR-0005, decision 4): each named, from the project's
 * root, so that a change to one decides how the gate runs as a change to the
 * config does; or why it reads one it cannot name, such as a path it builds
 * as it runs or a file a call opens, so that every change does.
 */
final readonly class ConfigReads
{
    private function __construct(private Paths $files, private string|NotGiven $unnamed)
    {
    }

    /** A config that reads no other file. */
    public static function none(): self
    {
        return new self(Paths::none(), NotGiven::value());
    }

    /** A config that reads these files, each spelt from the project's root. */
    public static function named(Path ...$files): self
    {
        return new self(Paths::of(...$files), NotGiven::value());
    }

    /** A config that reads a file it cannot name, for this reason. */
    public static function unnamed(string $why): self
    {
        return new self(Paths::none(), $why);
    }

    /** What this and another read together: both's files, and the first reason either cannot name one. */
    public function and(self $other): self
    {
        $files = $this->files;

        foreach ($other->files as $file) {
            $files = $files->with($file);
        }

        return new self($files, $this->unnamed instanceof NotGiven ? $other->unnamed : $this->unnamed);
    }

    /** The files it names, each once. */
    public function files(): Paths
    {
        return $this->files;
    }

    /** Why it reads a file it cannot name; nothing where it names every one. */
    public function unnamedBecause(): string|NotGiven
    {
        return $this->unnamed;
    }
}
