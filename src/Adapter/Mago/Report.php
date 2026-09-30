<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use NightWorksIO\MutationGate\Core\Analysis\AnalysisExit;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

use function sprintf;

/**
 * Mago's JSON report, as `--reporting-format=json` writes it: each issue
 * with its level, code and message. An `Error` is an error, and every lower
 * level, `Warning`, `Help` and `Note`, is lesser. Anything that is no
 * report means the analysis did not finish.
 */
final readonly class Report
{
    private const string ERROR = 'Error';

    private const string NO_REPORT = 'Mago wrote no report (%s).';

    public static function of(ChildProcess $mago): Findings|CannotJudge
    {
        $issues = Node::decode($mago->output())->field('issues');

        if (! AnalysisExit::finished($mago->exit()) || ! $issues->isPresent()) {
            return CannotJudge::because(sprintf(self::NO_REPORT, $mago->said()));
        }

        $findings = [];

        foreach (Lenient::items($issues) as $issue) {
            $code = Lenient::text($issue->field('code'));
            $message = Lenient::text($issue->field('message'));
            $findings[] = Lenient::text($issue->field('level')) === self::ERROR
                ? Finding::error($code, $message)
                : Finding::lesser($code, $message);
        }

        return Findings::of(...$findings);
    }
}
