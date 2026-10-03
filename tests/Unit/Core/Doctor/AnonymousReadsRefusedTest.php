<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\NotGiven;

it('names the account that refused and the URL forks read from, which the doctor\'s answers keep', function (): void {
    $refused = AnonymousReadsRefused::by('acme', 'https://acme.blob.core.windows.net/public');

    expect([$refused->account(), $refused->publicUrl()])->toBe(['acme', 'https://acme.blob.core.windows.net/public'])
        ->and(Asked::nothing()->withAnonymousReadsRefused($refused)->anonymousReads())->toBe($refused)
        ->and(Asked::nothing()->anonymousReads())->toEqual(NotGiven::value());
});
