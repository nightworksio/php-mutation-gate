<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * The script PHP prepends to PHPUnit for a mutant, with `auto_prepend_file`:
 * it registers the gate's `file://` wrapper before Composer's autoloader loads
 * anything, `files` autoloads included (ADR-0023 decision 9).
 */
final readonly class Override
{
    private const string SCRIPT = 'override.php';

    /** The script, written in the adapter's directory of a project, by its path. */
    public static function writtenFor(Project $project): string|CannotJudge
    {
        $script = sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s", MutantFile::registering());

        return $project->written(self::SCRIPT, $script);
    }
}
