<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function mb_strlen;
use function mb_substr;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * A directory on disk that paths are relative to, such as the project's root,
 * as the command line or the working directory spells it: absolute, or
 * relative to where the gate runs.
 */
final readonly class Root
{
    /** The working directory, where nothing else is named. */
    private const string HERE = '.';

    private function __construct(private string $value)
    {
    }

    /** A directory as it is spelt, without a trailing separator; nothing is the working directory. */
    public static function of(string $directory): self
    {
        $trimmed = rtrim($directory, '/');

        return new self(match (true) {
            $directory === '' => self::HERE,
            $trimmed === '' => '/',
            default => $trimmed,
        });
    }

    /** The working directory. */
    public static function here(): self
    {
        return new self(self::HERE);
    }

    /** Where a path under this directory is on disk; an absolute path is where it says. */
    public function at(Path $path): DiskPath
    {
        return DiskPath::of(match (true) {
            $path->isAbsolute() => $path->value(),
            $path->equals(Path::root()) => $this->value,
            default => sprintf('%s/%s', $this->prefix(), $path->value()),
        });
    }

    /** A file on disk as this directory spells it, the directory as the root, or as it is where it lies outside. */
    public function relative(string $file): Path
    {
        $prefix = sprintf('%s/', $this->prefix());

        return match (true) {
            $file === $this->value => Path::root(),
            str_starts_with($file, $prefix) => Path::of(mb_substr($file, mb_strlen($prefix))),
            default => Path::of($file),
        };
    }

    /** The directory as a path on disk. */
    public function path(): DiskPath
    {
        return DiskPath::of($this->value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** What a path under this directory starts with, before its separator. */
    private function prefix(): string
    {
        return $this->value === '/' ? '' : $this->value;
    }
}
