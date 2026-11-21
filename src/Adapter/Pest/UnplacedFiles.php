<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function explode;

use const FILE_APPEND;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function is_string;

use const LOCK_EX;

use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;
use function strval;

use Symfony\Component\Finder\SplFileInfo;

use function trim;
use function unlink;

/**
 * The files a part of a cut run made whole to number its mutants, as the
 * plan's table did not hold them (see ChangeTable): noted beside the run's
 * results, one a line, by its children too, and said once in the run's
 * result.
 *
 * @internal
 */
final readonly class UnplacedFiles
{
    private const string NOTE = '%s.unplaced';

    private const string LINE = "\n";

    private const string WARNING
        = 'Made %d %s whole to number the mutants of some lines, %s first: the plan\'s table did not match (ADR-0306).';

    /** Notes beside the run's results that it made a file whole to number the mutants of some of its lines. */
    public static function noted(SplFileInfo $file): void
    {
        $results = strval(getenv(GateVariable::Results->value));

        if ($results !== '') {
            file_put_contents(
                sprintf(self::NOTE, $results),
                sprintf('%s%s', strval($file->getRealPath()), self::LINE),
                FILE_APPEND | LOCK_EX,
            );
        }
    }

    /** What a run beside these results noted of the files it made whole, said once, and the note removed. */
    public static function warned(string $results, Project $project): Warnings
    {
        $note = sprintf(self::NOTE, $results);
        $text = is_file($note) ? file_get_contents($note) : false;
        self::cleared($results);
        $lines = explode(self::LINE, is_string($text) ? trim($text) : '');
        $files = array_values(array_unique(array_filter($lines, static fn(string $line): bool => $line !== '')));

        return $files === [] ? Warnings::none() : Warnings::of(Warning::that(sprintf(
            self::WARNING,
            count($files),
            count($files) === 1 ? 'file' : 'files',
            $project->relative($files[0])->value(),
        )));
    }

    /** Removes what an earlier run beside these results noted. */
    public static function cleared(string $results): void
    {
        $note = sprintf(self::NOTE, $results);
        is_file($note) ? unlink($note) : null;
    }
}
