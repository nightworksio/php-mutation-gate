<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Unkeyed;

it('says why a unit has no key', function (): void {
    expect(Unkeyed::because('There is no coverage map.')->why())->toBe('There is no coverage map.');
});
