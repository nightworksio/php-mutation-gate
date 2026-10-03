<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\Check\PublicContainer;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('advises allowing anonymous reads on an Azure account that refuses them', function (): void {
    $refused = AnonymousReadsRefused::by('acme', 'https://acme.blob.core.windows.net/public');

    expect(PublicContainer::in(Observations::none()->withAsked(Asked::nothing()->withAnonymousReadsRefused($refused))))
        ->toEqual(Findings::of(Finding::of(
            Slug::AnonymousReadsRefused,
            Severity::Advice,
            'The storage account acme refuses anonymous reads, so no fork reads the ledger at https://acme.blob.core.windows.net/public.',
            'The account\'s AllowBlobPublicAccess is off, which overrides every container\'s anonymous access level.',
            'Allow anonymous access on the account: az storage account update -n acme --allow-blob-public-access true',
        )));
});

it('finds nothing where no account was found refusing them', function (): void {
    expect(PublicContainer::in(Observations::none()))->toEqual(Findings::none());
});
