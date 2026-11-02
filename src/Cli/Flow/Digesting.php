<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Digest\Digest;
use NightWorksIO\MutationGate\Core\Report\Digest\DigestDetail;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;

/**
 * `digest`: the last run's verdict as the digest says it, rendered from the
 * report the verdict kept, running nothing (ADR-0009): the overview, or the
 * detail `--detail` asks for. A report not in the shape the gate writes is
 * refused with where it is not.
 *
 * @internal
 */
final readonly class Digesting
{
    public static function of(
        Directory $project,
        string|NotGiven $detail,
        SurvivorsShown $shown,
        GateCommand $gate,
    ): string|CannotJudge {
        $report = KeptReport::read($project, $gate);

        return $report instanceof Node ? self::rendered($report, $detail, $shown, $gate) : $report;
    }

    private static function rendered(
        Node $report,
        string|NotGiven $detail,
        SurvivorsShown $shown,
        GateCommand $gate,
    ): string|CannotJudge {
        try {
            return $detail instanceof NotGiven
                ? Digest::overview($report, $shown, $gate)
                : DigestDetail::of($report, $detail, $gate);
        } catch (NotInShape $notInShape) {
            return CannotJudge::because($notInShape->getMessage());
        }
    }
}
