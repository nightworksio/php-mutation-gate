<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** An Infection config whose `minMsi` or ignores the gate reads elsewhere (ADR-0016). */
final readonly class InfectionImport
{
    private const string FOUND = '%s %s.';

    private const string MIN_MSI = 'sets a minMsi';

    private const string IGNORES = 'ignores mutants by Infection\'s own rules';

    private const string WHY
        = 'The gate reads neither: floors replace minMsi, and ignores.entries replaces Infection\'s ignores.';

    private const string FIX
        = 'Run mutation-gate init --from=%s, which writes both into a config and says how each key maps.';

    public static function in(Observations $observed): Findings
    {
        $config = $observed->infection();

        if (! $config instanceof InfectionConfig || !$config->setsMinMsi() && !$config->ignores()) {
            return Findings::none();
        }

        $sets = [
            ...($config->setsMinMsi() ? [self::MIN_MSI] : []),
            ...($config->ignores() ? [self::IGNORES] : []),
        ];

        return Findings::of(Finding::of(
            Slug::InfectionConfigToImport,
            Severity::Advice,
            sprintf(self::FOUND, $config->file(), implode(' and ', $sets)),
            self::WHY,
            sprintf(self::FIX, $config->file()),
        ));
    }
}
