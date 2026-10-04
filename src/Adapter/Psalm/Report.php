<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function in_array;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

use function sprintf;

/**
 * Psalm's JSON report, as `--output-format=json` writes it: a list of
 * issues, each with its severity, type, message and the absolute path of its
 * file. An `error` is an error, and `info` is lesser. Psalm exits 0 when it
 * finds no error and 2 when it finds one; any other exit, or anything that
 * is no list, means the analysis did not finish.
 */
final readonly class Report
{
    /** The exits of a finished analysis: nothing found, and errors found. */
    private const array FINISHED = [0, 2];

    private const string ERROR = 'error';

    private const string NO_REPORT = 'Psalm wrote no report (%s).';

    /**
     * The findings a run reports, each in its file as the project names it
     * under this config; or why it reports none.
     */
    public static function of(ChildProcess $psalm, FindingFiles $files, PsalmXml $xml): Findings|CannotJudge
    {
        $issues = Node::decode($psalm->output());
        $listed = in_array($issues->kind(), [Kind::List, Kind::Empty], strict: true);

        if (! in_array($psalm->exit(), self::FINISHED, strict: true) || ! $listed) {
            return CannotJudge::because(sprintf(self::NO_REPORT, $psalm->said()));
        }

        $findings = [];

        foreach (Lenient::items($issues) as $issue) {
            $file = $files->of($xml->spelt(Lenient::text($issue->field('file_path'))));
            $type = Lenient::text($issue->field('type'));
            $message = Lenient::text($issue->field('message'));
            $findings[] = Lenient::text($issue->field('severity')) === self::ERROR
                ? Finding::error($file, $type, $message)
                : Finding::lesser($file, $type, $message);
        }

        return Findings::of(...$findings);
    }
}
