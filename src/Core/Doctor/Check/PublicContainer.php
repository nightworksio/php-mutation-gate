<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * Under `--online`, an Azure storage account that refuses anonymous reads,
 * which keeps a job without credentials, such as a fork's, from reading
 * the default branch's ledger from the public container (ADR-0028
 * decision 4).
 */
final readonly class PublicContainer
{
    private const string FOUND = 'The storage account %s refuses anonymous reads, so no fork reads the ledger at %s.';

    private const string WHY
        = 'The account\'s AllowBlobPublicAccess is off, which overrides every container\'s anonymous access level.';

    private const string FIX
        = 'Allow anonymous access on the account: az storage account update -n %s --allow-blob-public-access true';

    public static function in(Observations $observed): Findings
    {
        $refused = $observed->asked()->anonymousReads();

        return $refused instanceof AnonymousReadsRefused ? Findings::of(self::of($refused)) : Findings::none();
    }

    private static function of(AnonymousReadsRefused $refused): Finding
    {
        return Finding::of(
            Slug::AnonymousReadsRefused,
            Severity::Advice,
            sprintf(self::FOUND, $refused->account(), $refused->publicUrl()),
            self::WHY,
            sprintf(self::FIX, $refused->account()),
        );
    }
}
