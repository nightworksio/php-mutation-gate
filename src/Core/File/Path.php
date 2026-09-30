<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_filter;
use function basename;
use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;

/**
 * A path as the repository spells it: relative to its root, separated by `/`,
 * with no `.` segment and no trailing separator. The root itself is `.`.
 */
final readonly class Path
{
    /** A `..` segment, which goes up out of the directory it is in. */
    private const string UP = '#(?:^|/)\.\.(?:/|$)#';

    /** How the name of a PHP file ends. */
    private const string PHP = '.php';

    private const string ROOT = '.';

    /** @param non-empty-string $value */
    private function __construct(private string $value)
    {
    }

    public static function of(string $path): self
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn(string $segment): bool => $segment !== '' && $segment !== self::ROOT,
        );
        $normalised = implode('/', $segments);

        return new self(match (true) {
            $normalised === '' => self::ROOT,
            str_starts_with($path, '/') => sprintf('/%s', $normalised),
            default => $normalised,
        });
    }

    public static function root(): self
    {
        return new self(self::ROOT);
    }

    /** @return non-empty-string */
    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** Whether this path is a directory's own path or a path inside it. Every path is inside the root. */
    public function within(self $directory): bool
    {
        return $directory->value === self::ROOT
            || $this->value === $directory->value
            || str_starts_with($this->value, sprintf('%s/', $directory->value));
    }

    /** This path as a directory it is inside spells it; a path not inside it is answered as it is. */
    public function relativeTo(self $directory): self
    {
        $prefix = sprintf('%s/', $directory->value);

        return str_starts_with($this->value, $prefix) ? self::of(mb_substr($this->value, mb_strlen($prefix))) : $this;
    }

    /** Whether this is the path of a PHP file. */
    public function isPhp(): bool
    {
        return str_ends_with($this->value, self::PHP);
    }

    /** The name of the file this path ends in, without its `.php` where it has one: a PHP file's class name. */
    public function stem(): string
    {
        return basename($this->value, self::PHP);
    }

    /** The path of an entry inside this directory, spelt as a path from it. */
    public function child(self $entry): self
    {
        return self::of(sprintf('%s/%s', $this->value, $entry->value));
    }

    /** Whether this path leads out of the directory it is spelt from: it is absolute, or goes up through `..`. */
    public function escapes(): bool
    {
        return $this->isAbsolute() || preg_match(self::UP, $this->value) === 1;
    }

    /** Whether this path is spelt from the file system's root rather than from a directory. */
    public function isAbsolute(): bool
    {
        return str_starts_with($this->value, '/');
    }
}
