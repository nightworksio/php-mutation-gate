<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function array_any;
use function array_filter;
use function array_keys;
use function implode;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * No GitHub workflow that runs the gate has a `schedule` trigger (ADR-0108).
 * A release of the gate that moves under a tag judges nothing until a full
 * run reaches the units no change does, and the scheduled run is that run.
 *
 * @internal
 */
final readonly class ScheduledRun
{
    /** A `schedule` key on a line, outside a comment. */
    private const string TRIGGER = '/^[^#\n]*\bschedule\s*:/m';

    private const string FOUND = 'No schedule triggers %s, the workflows that run the gate.';

    private const string WHY
        = 'A full run on a schedule reaches the units no change reaches, and judges them with the gate of the day.';

    public static function in(Observations $observed): Findings
    {
        $definitions = $observed->files()->runningTheGate();

        return $definitions instanceof GateDefinitions ? self::of($definitions) : Findings::none();
    }

    private static function of(GateDefinitions $definitions): Findings
    {
        $workflows = array_filter(
            iterator_to_array($definitions, preserve_keys: true),
            static fn(string $definition): bool => str_starts_with($definition, Definitions::GITHUB),
            ARRAY_FILTER_USE_KEY,
        );
        $scheduled = array_any(
            $workflows,
            static fn(string $text): bool => preg_match(self::TRIGGER, $text) === 1,
        );

        return $workflows === [] || $scheduled
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::NoScheduledRun,
                Severity::Advice,
                sprintf(self::FOUND, implode(Fit::COMMA, array_keys($workflows))),
                self::WHY,
                ScheduleRunning::NOT_RUN_FIX,
            ));
    }
}
