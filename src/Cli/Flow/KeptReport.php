<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The last run's JSON report, which every verdict keeps in the workspace
 * for `digest` to render later, running nothing (ADR-0009). A report another
 * format of it wrote is refused, as the digest would misread it.
 *
 * @internal
 */
final readonly class KeptReport
{
    private const string NOT_KEPT = 'The run\'s report could not be kept for digest: %s';

    private const string NONE = 'No run has left its report at %s here: run %s first.';

    private const string OTHER_FORMAT
        = 'The report at %s is in another format than this gate reads: run %s again.';

    /** @return list<string> why the report was not kept; nothing where it was */
    public static function keep(Directory $project, Verdict $verdict): array
    {
        $kept = $project->write(Workspace::report(), Contents::of(JsonReport::encode($verdict)));

        return $kept instanceof CannotJudge ? [sprintf(self::NOT_KEPT, $kept->why())] : [];
    }

    /** The last run's report, or why there is none to read. */
    public static function read(Directory $project, GateCommand $gate): Node|CannotJudge
    {
        $path = Workspace::report();
        $contents = $project->read($path);

        if (! $contents instanceof Contents) {
            return $contents instanceof Missing
                ? CannotJudge::because(sprintf(self::NONE, $path->value(), $gate->gate()))
                : $contents;
        }

        $report = Node::decode($contents->text(), $path->value());

        try {
            return $report->field('format')->integer() === JsonReport::FORMAT
                ? $report
                : CannotJudge::because(sprintf(self::OTHER_FORMAT, $path->value(), $gate->gate()));
        } catch (NotInShape $notInShape) {
            return CannotJudge::because($notInShape->getMessage());
        }
    }
}
