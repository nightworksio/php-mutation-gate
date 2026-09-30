<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/**
 * The script PHP prepends to PHPUnit for a mutant, with `auto_prepend_file`:
 * it registers the gate's `file://` wrapper before Composer's autoloader loads
 * anything, `files` autoloads included (ADR-0023 decision 9).
 */
final readonly class Override
{
    private const string SCRIPT = 'override.php';

    /**
     * What PHP's command line reads as ini syntax in a setting's value, which
     * it quotes: a quote, `${`, and a backslash before a backslash.
     */
    private const string INI_SYNTAX = '/"|\$\{|\\\\\\\\/';

    private const string UNPREPENDABLE
        = 'PHP cannot prepend %s: its command line reads a ", a ${ or a \\\\ in a setting as ini syntax.';

    /**
     * The script, written in the adapter's directory of a project, by its
     * path; or why PHP cannot be started with it, where its path would not
     * reach PHP as it is.
     */
    public static function writtenFor(Project $project): string|CannotJudge
    {
        $script = sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s",
            MutantFile::registering(Variable::Mutant->value, Variable::Mutated->value, Variable::Guard->value),
        );

        return preg_match(self::INI_SYNTAX, $project->own(self::SCRIPT)) === 1
            ? CannotJudge::because(sprintf(self::UNPREPENDABLE, $project->own(self::SCRIPT)))
            : $project->written(self::SCRIPT, $script);
    }
}
