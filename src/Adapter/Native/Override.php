<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\IniPath;

use function sprintf;
use function var_export;

/**
 * The script PHP prepends to the framework for a mutant, with
 * `auto_prepend_file`: it registers the gate's `file://` wrapper before
 * Composer's autoloader loads anything, `files` autoloads included
 * (ADR-0023).
 */
final readonly class Override
{
    private const string SCRIPT = 'override.php';

    /** The lines that load the gate's PHAR and its autoloader. */
    private const string LOADING = "%srequire %s;\n";

    private const string UNPREPENDABLE
        = 'PHP cannot prepend %s: its command line reads a ", a ${ or a \\\\ in a setting as ini syntax.';

    /**
     * The script, written in the adapter's directory of a project, by its
     * path; or why PHP cannot be started with it, where its path would not
     * reach PHP as it is. Under the gate's PHAR, it loads the archive's
     * autoloader first.
     */
    public static function writtenFor(Project $project, OwnArchive|NotGiven $archive): string|CannotJudge
    {
        $path = self::pathIn($project);
        $loading = $archive instanceof OwnArchive
            ? sprintf(self::LOADING, $archive->loading(), var_export($archive->autoloader(), return: true))
            : '';
        $script = sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s%s",
            $loading,
            MutantFile::registering(
                Variable::Mutant->value,
                Variable::Mutated->value,
                Variable::Guard->value,
                Guard::SERVED,
            ),
        );

        return IniPath::isPlain($path)
            ? $project->written(self::SCRIPT, $script)
            : CannotJudge::because(sprintf(self::UNPREPENDABLE, $path));
    }

    /** Where the script is, in the adapter's directory of a project, written or not. */
    public static function pathIn(Project $project): string
    {
        return $project->own(self::SCRIPT);
    }
}
