<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\GatePins;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A CI definition that pins another release of the gate than the one the
 * project installed (ADR-0083): CI then runs a gate the project never
 * tested, whose config and ledger may differ from the installed one's.
 *
 * @internal
 */
final readonly class WorkflowPin
{
    private const string FOUND = '%s pins %s, and the project installed %s.';

    private const string WHY
        = 'CI runs the release a definition pins, so it judges with a gate other than the one installed here.';

    private const string FIX = 'Pin %s in %s, as mutation-gate doctor --fix does.';

    public static function in(Observations $observed): Findings
    {
        $definitions = $observed->files()->runningTheGate();
        $run = $observed->run();

        if (! $definitions instanceof GateDefinitions || ! $run instanceof DoctorRun) {
            return Findings::none();
        }

        $installed = $run->pin();
        $findings = Findings::none();

        foreach ($definitions as $definition => $text) {
            $stale = GatePins::stale($text, $installed);
            $findings = $stale === [] ? $findings : $findings->and(Findings::of(Finding::of(
                Slug::WorkflowPinStale,
                Severity::Advice,
                sprintf(self::FOUND, $definition, implode(Fit::COMMA, $stale), $installed->version()),
                self::WHY,
                sprintf(self::FIX, $installed->pin(), $definition),
            )));
        }

        return $findings;
    }
}
