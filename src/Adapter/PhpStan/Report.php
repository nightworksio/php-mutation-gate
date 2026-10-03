<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function implode;
use function mb_strstr;

use NightWorksIO\MutationGate\Core\Analysis\AnalysisExit;
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
 * PHPStan's JSON report, as `--error-format=json` writes it: each file's
 * messages, each with its identifier, all of them errors, since PHPStan has
 * no lower level. A file is named by its path, which for an error in a
 * trait is followed by the class it was analysed in the context of; the
 * finding sits in the trait's file. An error that belongs to no file, such
 * as its own, means the analysis did not finish, and so does anything that
 * is no report.
 */
final readonly class Report
{
    private const string NO_REPORT = 'PHPStan wrote no report (%s).';

    private const string UNFINISHED = 'PHPStan did not finish its analysis: %s';

    /** What follows a trait's path where PHPStan names the class it analysed the trait in, as PHPStan finds it. */
    private const string IN_CONTEXT = ' (in context of ';

    public static function of(ChildProcess $phpstan, FindingFiles $files): Findings|CannotJudge
    {
        $report = Node::decode($phpstan->output());

        return AnalysisExit::finished($phpstan->exit()) && $report->field('files')->isPresent()
            ? self::read($report, $files)
            : CannotJudge::because(sprintf(self::NO_REPORT, $phpstan->said()));
    }

    private static function read(Node $report, FindingFiles $files): Findings|CannotJudge
    {
        $general = [];

        foreach (Lenient::items($report->field('errors')) as $error) {
            $general[] = Lenient::text($error);
        }

        return $general === [] ? self::findings($report, $files) : CannotJudge::because(
            sprintf(self::UNFINISHED, implode(' ', $general)),
        );
    }

    private static function findings(Node $report, FindingFiles $files): Findings
    {
        $findings = [];
        $reported = $report->field('files');

        foreach ($reported->kind() === Kind::Map ? Lenient::entries($reported) : [] as $named => $file) {
            $path = $files->of(self::outOfContext(sprintf('%s', $named)));

            foreach (Lenient::items($file->field('messages')) as $message) {
                $findings[] = Finding::error(
                    $path,
                    Lenient::text($message->field('identifier')),
                    Lenient::text($message->field('message')),
                );
            }
        }

        return Findings::of(...$findings);
    }

    /** A file as PHPStan names it, without the class it analysed a trait in the context of. */
    private static function outOfContext(string $named): string
    {
        $path = mb_strstr($named, self::IN_CONTEXT, before_needle: true);

        return $path === false ? $named : $path;
    }
}
