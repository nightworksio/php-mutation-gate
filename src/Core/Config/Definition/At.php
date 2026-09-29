<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function sprintf;

/** Where a value sits in a config, as a problem names it: `trees[1].floor`, `costs.secondsPerLine["src"]`. */
final readonly class At
{
    /** A key of the object at a path. The keys of the whole config have no path before them. */
    public static function key(string $at, string $key): string
    {
        return $at === '' ? $key : sprintf('%s.%s', $at, $key);
    }

    /** An entry of the list at a path. */
    public static function index(string $at, int $index): string
    {
        return sprintf('%s[%d]', $at, $index);
    }

    /** An entry of the map at a path, whose keys are data rather than settings. */
    public static function entry(string $at, string $key): string
    {
        return sprintf('%s["%s"]', $at, $key);
    }
}
