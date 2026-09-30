<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\NativeMarkers as Native;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** A runner's own ignore markers where `ignores.native` refuses them (ADR-0017, decision 7). */
final readonly class NativeMarkers
{
    private const string FOUND = '%d native %s: %s.';

    private const string AT = '%s, %s';

    private const string IN = '%s in %s()';

    private const string WHY
        = 'A marker hides mutants with no reason and no end, so under ignores.native: refuse a run stops at once.';

    private const string FIX
        = 'Replace each with its ignores.entries entry, with a reason: %s. Or set ignores.native: allow.';

    private const string REPLACED = '%s with %s';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $markers = $observed->markers();
        $refused = $settings instanceof Settings && $settings->ignores()->native() === Native::Refuse;

        if (! $refused || ! $markers instanceof Markers || count($markers) === 0) {
            return Findings::none();
        }

        $found = [];
        $replaced = [];

        foreach ($markers as $marker) {
            $found[] = sprintf(self::AT, self::where($marker), $marker->marker());
            $replaced[] = sprintf(self::REPLACED, $marker->where(), $marker->replacement());
        }

        return Findings::of(Finding::of(
            Slug::NativeMarkersRefused,
            Severity::WillFail,
            sprintf(self::FOUND, count($markers), count($markers) === 1 ? 'marker' : 'markers', implode('; ', $found)),
            self::WHY,
            sprintf(self::FIX, implode('; ', $replaced)),
        ));
    }

    /** Where a marker is, and the function it is in or documents. */
    private static function where(Marker $marker): string
    {
        $enclosing = $marker->enclosing();

        return $enclosing instanceof Enclosing
            ? sprintf(self::IN, $marker->where(), $enclosing->function())
            : $marker->where();
    }
}
