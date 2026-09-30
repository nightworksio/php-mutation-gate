<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function sprintf;

/** Where a value sits in a config, as a problem names it: `trees[1].floor`, `costs.secondsPerLine["src"]`. */
final readonly class At
{
    /** An entry of the list at a path. */
    public static function index(string $at, int $index): string
    {
        return sprintf('%s[%d]', $at, $index);
    }
}
