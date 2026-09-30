<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function implode;

use NightWorksIO\MutationGate\Core\Analysis\AnalysisExit;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

use function sprintf;

/**
 * PHPStan's JSON report, as `--error-format=json` writes it: each file's
 * messages, each with its identifier, all of them errors, since PHPStan has
 * no lower level. An error that belongs to no file, such as its own, means
 * the analysis did not finish, and so does anything that is no report.
 */
final readonly class Report
{
    private const string NO_REPORT = 'PHPStan wrote no report (%s).';

    private const string UNFINISHED = 'PHPStan did not finish its analysis: %s';

    public static function of(ChildProcess $phpstan): Findings|CannotJudge
    {
        $report = Node::decode($phpstan->output());

        return AnalysisExit::finished($phpstan->exit()) && $report->field('files')->isPresent()
            ? self::read($report)
            : CannotJudge::because(sprintf(self::NO_REPORT, $phpstan->said()));
    }

    private static function read(Node $report): Findings|CannotJudge
    {
        $general = [];

        foreach (Lenient::items($report->field('errors')) as $error) {
            $general[] = Lenient::text($error);
        }

        return $general === [] ? self::findings($report) : CannotJudge::because(
            sprintf(self::UNFINISHED, implode(' ', $general)),
        );
    }

    private static function findings(Node $report): Findings
    {
        $findings = [];
        $files = $report->field('files');

        foreach ($files->kind() === Kind::Map ? Lenient::entries($files) : [] as $file) {
            foreach (Lenient::items($file->field('messages')) as $message) {
                $findings[] = Finding::error(
                    Lenient::text($message->field('identifier')),
                    Lenient::text($message->field('message')),
                );
            }
        }

        return Findings::of(...$findings);
    }
}
