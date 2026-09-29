<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Warning;

it('holds what every report shows', function (): void {
    expect(Warning::that('The ignore of 3f9a1c2b7d04 expires on 2027-03-31.')->text())->toBe('The ignore of 3f9a1c2b7d04 expires on 2027-03-31.');
});
