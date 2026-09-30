<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;

use DateTimeImmutable;

use function dirname;
use function explode;
use function implode;
use function ltrim;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function ord;
use function sprintf;
use function str_starts_with;

/**
 * What git's log says of when paths last changed: the log asked for, and each
 * asked path's newest commit read from what it printed, a directory by the
 * newest of its files.
 */
final readonly class History
{
    /** What starts each commit's line in the log: a byte no path holds, then its commit time. */
    private const string COMMIT = "\x01";

    /** The directory `dirname` gives a path with none. */
    private const string TOP = '.';

    /**
     * The log of HEAD for these paths, newest first, each commit its time and
     * the files it changed, reading the paths from its input.
     *
     * @return list<string>
     */
    public static function arguments(): array
    {
        return [
            'log',
            '--stdin',
            '-z',
            sprintf('--format=%%x%02x%%ct', ord(self::COMMIT[0])),
            '--name-only',
            '--no-renames',
            '--relative',
        ];
    }

    /** What the log reads from its input: HEAD, and these paths. */
    public static function input(Paths $paths): string
    {
        $lines = ['HEAD', '--'];

        foreach ($paths as $path) {
            $lines[] = $path->value();
        }

        return sprintf("%s\n", implode("\n", $lines));
    }

    /**
     * When each of these paths last changed, as the log printed it; a path
     * no commit changed has none.
     *
     * @return ByPath<Instant>
     */
    public static function lastChanged(string $printed, Paths $asked): ByPath
    {
        $wanted = self::byValue($asked);
        $changed = ByPath::none();
        $seen = [];
        $at = Instant::at(new DateTimeImmutable('@0'));

        foreach (explode("\0", $printed) as $entry) {
            $entry = ltrim($entry, "\n");

            if (str_starts_with($entry, self::COMMIT)) {
                $at = Instant::at(new DateTimeImmutable(sprintf('@%s', mb_substr($entry, 1))));
            }

            foreach (str_starts_with($entry, self::COMMIT) ? [] : self::owners($entry, $wanted) as $owner) {
                $changed = array_key_exists($owner, $seen) ? $changed : $changed->with($wanted[$owner], $at);
                $seen[$owner] = true;
            }
        }

        return $changed;
    }

    /**
     * These paths, by what each is.
     *
     * @return array<string, Path>
     */
    private static function byValue(Paths $paths): array
    {
        $by = [];

        foreach ($paths as $path) {
            $by[$path->value()] = $path;
        }

        return $by;
    }

    /**
     * The asked paths a file is, or is inside; none for no file.
     *
     * @param  array<string, Path> $wanted
     * @return list<string>
     */
    private static function owners(string $file, array $wanted): array
    {
        $owners = [];
        $path = $file;

        while ($path !== self::TOP) {
            $owners = array_key_exists($path, $wanted) ? [...$owners, $path] : $owners;
            $parent = dirname($path);
            $path = $parent === $path ? self::TOP : $parent;
        }

        return $owners;
    }
}
