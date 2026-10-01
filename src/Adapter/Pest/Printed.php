<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\DiffPatch;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function sprintf;

/**
 * A file as Pest prints each of its mutants: parsed for the newest PHP the
 * parser knows and printed whole by php-parser's standard printer, as Pest's
 * own mutation does before it diffs the original against the mutant. Pest's
 * diff is between two such prints, so it goes onto this print, and not onto
 * the file as written. The mutant's line is the file's, not the print's, so
 * the diff goes back only where its lines stand in one place of the print.
 */
final readonly class Printed
{
    private const string UNREAD = 'The gate cannot read %s to check its mutant.';

    private const string UNPARSED = '%s does not parse, so its mutant cannot be printed as Pest prints it: %s';

    public static function checkable(Project $project, Mutant $mutant): Checkable|CannotJudge
    {
        $file = $mutant->location()->file();
        $absolute = $project->absolute($file);
        $written = is_file($absolute) ? file_get_contents($absolute) : false;
        $printed = $written === false
            ? CannotJudge::because(sprintf(self::UNREAD, $file->value()))
            : self::printed($written, $file->value());
        $patched = $printed instanceof Contents
            ? DiffPatch::of($mutant->mutation())->ontoTheOnlyPlace($printed, $file)
            : $printed;

        return match (true) {
            ! $printed instanceof Contents => $printed,
            ! $patched instanceof Contents => $patched,
            default => Checkable::printed($printed, $patched),
        };
    }

    private static function printed(string $written, string $file): Contents|CannotJudge
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($written);
        } catch (Error $error) {
            return CannotJudge::because(sprintf(self::UNPARSED, $file, $error->getMessage()));
        }

        return Contents::of(new Standard()->prettyPrintFile($statements ?? []));
    }
}
