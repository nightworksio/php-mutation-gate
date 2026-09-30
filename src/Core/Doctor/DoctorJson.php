<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;

/**
 * `doctor --format=json`: every finding, with its slug and link, and whether
 * any would fail a run. Public API (ADR-0011, decision 7), described by
 * `resources/doctor.schema.json`.
 */
final readonly class DoctorJson
{
    public const int FORMAT = 1;

    public static function of(Findings $findings, Guide $guide): string
    {
        $listed = [];

        foreach ($findings as $finding) {
            $seconds = $finding->atStake();
            $listed[] = [
                'slug' => $finding->slug()->value,
                'severity' => $finding->severity()->value,
                'found' => $finding->found(),
                'why' => $finding->why(),
                'fix' => $finding->fix(),
                ...($seconds instanceof Seconds ? ['seconds' => $seconds->seconds()] : []),
                'link' => $guide->link($finding->slug()),
            ];
        }

        return JsonText::encode([
            'format' => self::FORMAT,
            'failsARun' => $findings->failARun(),
            'findings' => $listed,
        ]);
    }
}
