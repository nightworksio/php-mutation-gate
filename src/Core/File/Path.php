<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_filter;
use function array_last;
use function array_map;
use function array_pop;
use function array_slice;
use function basename;
use function count;
use function dirname;
use function explode;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_substr;
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
    /** The segment that goes up out of the directory it is in. */
    private const string UP = '..';

    /** Where an absolute path is spelt from: the file system's root. */
    private const string FILE_SYSTEM = '/';

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
            str_starts_with($path, self::FILE_SYSTEM) => sprintf('%s%s', self::FILE_SYSTEM, $normalised),
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

    /** The directory at the root this path lies in, such as `src` for `src/Money.php`; the root for a file in it. */
    public function top(): self
    {
        $segments = $this->segments();

        return count($segments) > 1 ? self::of($segments[0]) : self::root();
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

    /** The directory this path is in: the root for a path of one segment. */
    public function directory(): self
    {
        return self::of(dirname($this->value));
    }

    /** The path of an entry inside this directory, spelt as a path from it. */
    public function child(self $entry): self
    {
        return self::of(sprintf('%s/%s', $this->value, $entry->value));
    }

    /** Whether this path leads out of the directory it is spelt from: it is absolute, or goes up through `..`. */
    public function escapes(): bool
    {
        return $this->isAbsolute() || in_array(self::UP, $this->segments(), strict: true);
    }

    /** Whether this path is spelt from the file system's root rather than from a directory. */
    public function isAbsolute(): bool
    {
        return str_starts_with($this->value, self::FILE_SYSTEM);
    }

    /** Where this path is spelt from, as a directory names it: `/` for an absolute path, `.` for any other. */
    public function base(): string
    {
        return $this->isAbsolute() ? self::FILE_SYSTEM : self::ROOT;
    }

    /** This path as its base spells it: `/tmp/report.json` is `tmp/report.json`, and a relative path is itself. */
    public function fromBase(): self
    {
        return $this->isAbsolute() ? self::of(mb_substr($this->value, mb_strlen(self::FILE_SYSTEM))) : $this;
    }

    /**
     * The path with each `..` taking back the directory before it: `ci/../src` is `src`. A `..` with nothing
     * before it to take back, at the start of a relative path or right after the file system's root, stays.
     */
    public function collapsed(): self
    {
        $segments = [];

        foreach (explode('/', $this->value) as $segment) {
            $last = $segments === [] ? self::UP : array_last($segments);

            if ($segment === self::UP && $last !== self::UP && $last !== '') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return self::of(implode('/', $segments));
    }

    /**
     * This path as a directory spells it, both spelt from the same base: `src` from `ci` is `../src`, and
     * `/project/src` from `/project` is `src`. A path spelt from the other base is itself.
     */
    public function from(self $directory): self
    {
        if ($this->isAbsolute() !== $directory->isAbsolute()) {
            return $this;
        }

        $target = $this->segments();
        $base = $directory->segments();
        $shared = 0;

        while ($shared < count($base) && $shared < count($target) && $base[$shared] === $target[$shared]) {
            $shared++;
        }

        $up = self::of(implode('/', array_map(static fn(): string => self::UP, array_slice($base, $shared))));

        return $up->child(self::of(implode('/', array_slice($target, $shared))));
    }

    /** @return list<string> the path's segments, none for the root; an absolute path's first is `''` */
    private function segments(): array
    {
        return $this->value === self::ROOT ? [] : explode('/', $this->value);
    }
}
