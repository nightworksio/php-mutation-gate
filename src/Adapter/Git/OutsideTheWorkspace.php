<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use NightWorksIO\MutationGate\Core\File\Workspace;

use function sprintf;

/**
 * The pathspec for everything in the repository but the gate's own workspace:
 * what the gate writes, or checks out, while it runs is never a change, and
 * never makes a working tree hold more than its commit. A pathspec that only
 * excludes matches everything else, and git reads an argument of pathspec
 * magic as a path, so it needs neither `.` nor `--`.
 */
final readonly class OutsideTheWorkspace
{
    /** @return list<string> */
    public static function pathspec(): array
    {
        return [sprintf(':(exclude)%s', Workspace::root()->value())];
    }
}
