<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** The PHP the runner uses loads no coverage driver, so no run can map a line to its tests. */
final readonly class CoverageDriver
{
    private const string FOUND = 'The PHP the runner runs its tests on, %s, loads neither pcov nor Xdebug.';

    private const string WHY
        = 'The gate learns which tests run each line from coverage, so without a driver no run can judge a mutant.';

    private const string LOAD = 'Load pcov, which is installed: add extension=pcov to %s.';

    private const string INSTALL = 'Install pcov with pecl install pcov, then add extension=pcov to %s.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();

        if (! $php instanceof RunnerPhp || $php->loads(Driver::PCOV) || $php->loads(Driver::XDEBUG)) {
            return Findings::none();
        }

        return Findings::of(Finding::of(
            Slug::NoCoverageDriver,
            Severity::WillFail,
            sprintf(self::FOUND, $php->binary()),
            self::WHY,
            sprintf($php->offers(Driver::PCOV) ? self::LOAD : self::INSTALL, $php->iniFile()),
        ));
    }
}
