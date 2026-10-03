<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use NightWorksIO\MutationGate\Core\Analysis\AnalysisExit;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

use function sprintf;

/**
 * Mago's JSON report, as `--reporting-format=json` writes it: each issue
 * with its level, code, message and annotations. An `Error` is an error,
 * and every lower level, `Warning`, `Help` and `Note`, is lesser. An issue
 * sits in the file of its primary annotation, which Mago names by the path
 * it read, the substitute's for a mutant. An issue with no primary
 * annotation belongs to no file, and like anything that is no report,
 * means the analysis did not finish.
 */
final readonly class Report
{
    private const string ERROR = 'Error';

    /** The kind of the annotation that marks where an issue is. */
    private const string PRIMARY = 'Primary';

    private const string NO_REPORT = 'Mago wrote no report (%s).';

    private const string UNPLACED = 'Mago reported %s in no file.';

    public static function of(ChildProcess $mago, FindingFiles $files): Findings|CannotJudge
    {
        $issues = Node::decode($mago->output())->field('issues');

        if (! AnalysisExit::finished($mago->exit()) || ! $issues->isPresent()) {
            return CannotJudge::because(sprintf(self::NO_REPORT, $mago->said()));
        }

        $findings = [];

        foreach (Lenient::items($issues) as $issue) {
            $code = Lenient::text($issue->field('code'));
            $named = self::placed($issue);

            if ($named === '') {
                return CannotJudge::because(sprintf(self::UNPLACED, $code));
            }

            $message = Lenient::text($issue->field('message'));
            $findings[] = Lenient::text($issue->field('level')) === self::ERROR
                ? Finding::error($files->of($named), $code, $message)
                : Finding::lesser($files->of($named), $code, $message);
        }

        return Findings::of(...$findings);
    }

    /** The path of the file an issue's primary annotation is in, as Mago read it; nothing where it has none. */
    private static function placed(Node $issue): string
    {
        foreach (Lenient::items($issue->field('annotations')) as $annotation) {
            if (Lenient::text($annotation->field('kind')) === self::PRIMARY) {
                return Lenient::text($annotation->field('span')->field('file_id')->field('path'));
            }
        }

        return '';
    }
}
