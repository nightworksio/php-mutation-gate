<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A config every command refuses. Two runners left to choose between are the
 * runners check's, which says what to set.
 */
final readonly class Config
{
    private const string FOUND = 'The config cannot be used: %s';

    private const string PROBLEM = '%s: %s';

    private const string WHY = 'Every command reads the config first, so none can run until it is right.';

    private const string FIX
        = 'Correct each problem named, then run mutation-gate config:show to see the config the gate would use.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $runners = $observed->runners();

        if (!$settings instanceof Invalid && !$settings instanceof CannotJudge
            || $runners instanceof InstalledRunners && $runners->leaveTheChoiceOpen()) {
            return Findings::none();
        }

        return Findings::of(Finding::of(
            Slug::ConfigRefused,
            Severity::WillFail,
            sprintf(self::FOUND, self::problems($settings)),
            self::WHY,
            self::FIX,
        ));
    }

    private static function problems(Invalid|CannotJudge $refused): string
    {
        if ($refused instanceof CannotJudge) {
            return $refused->why();
        }

        $problems = [];

        foreach ($refused as $problem) {
            $problems[] = $problem->path() === ''
                ? $problem->message()
                : sprintf(self::PROBLEM, $problem->path(), $problem->message());
        }

        return implode(' ', $problems);
    }
}
