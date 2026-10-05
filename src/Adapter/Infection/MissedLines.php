<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;

use function sprintf;

/**
 * The executable lines no test ran, from the Clover report PHPUnit writes
 * beside its XML coverage, which leaves them out: each statement line Clover
 * counts no run of, as the project spells its file, with no test. Clover's
 * shape is the same in every PHPUnit the gate drives, where the
 * `--coverage-php` report's format moves with php-code-coverage.
 */
final readonly class MissedLines
{
    /** The report's name in a coverage directory. */
    public const string FILE = 'clover.xml';

    private const string UNREADABLE
        = '%s is not there or is not a Clover report, so the gate cannot say which lines no test ran.';

    /**
     * The missed lines a coverage directory's report holds, or why it cannot be read.
     *
     * @return list<CoveredLine>|CannotJudge
     */
    public static function in(Project $project, DiskPath $directory): array|CannotJudge
    {
        $file = $directory->child(self::FILE)->value();
        $report = XmlFile::read($file, CannotJudge::because(sprintf(self::UNREADABLE, $file)));

        return $report instanceof DOMDocument ? self::missedIn($project, $report) : $report;
    }

    /** @return list<CoveredLine> */
    private static function missedIn(Project $project, DOMDocument $report): array
    {
        $missed = [];

        foreach ($report->getElementsByTagName('file') as $file) {
            $path = $project->relative($file->getAttribute('name'));

            foreach ($file->getElementsByTagName('line') as $line) {
                if (self::isMissed($line)) {
                    $missed[] = CoveredLine::of($path, (int) $line->getAttribute('num'));
                }
            }
        }

        return $missed;
    }

    /** Whether a line is a statement Clover counts no run of. */
    private static function isMissed(DOMElement $line): bool
    {
        return $line->getAttribute('type') === 'stmt' && $line->getAttribute('count') === '0';
    }
}
