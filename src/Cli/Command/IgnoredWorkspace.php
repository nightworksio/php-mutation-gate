<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;

use function sprintf;
use function str_ends_with;

/** The gate's own directory kept out of git: the line `init` adds to `.gitignore` where it is not there. */
final readonly class IgnoredWorkspace
{
    /** Whether `.mutation-gate/` had to be added to `.gitignore`, as `init` adds it. */
    public static function in(Directory $project): bool|CannotJudge
    {
        $gitignore = $project->read(Path::of(GitIgnore::FILE));
        $text = $gitignore instanceof Contents ? $gitignore->text() : '';

        if ($gitignore instanceof CannotJudge) {
            return $gitignore;
        }

        if (GitIgnore::of($text)->names(Workspace::root())) {
            return false;
        }

        $added = $project->write(
            Path::of(GitIgnore::FILE),
            Contents::of(
                sprintf(
                    '%s%s%s',
                    $text,
                    $text === '' || str_ends_with($text, "\n") ? '' : "\n",
                    sprintf("%s\n", self::line()),
                ),
            ),
        );

        return $added instanceof CannotJudge ? $added : true;
    }

    /** The gate's own directory, as `init` adds it to `.gitignore`. */
    public static function line(): string
    {
        return sprintf('%s/', Workspace::root()->value());
    }
}
