<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Staged;

it('writes a file beside its own name before it is moved there', function (): void {
    expect(Staged::beside('/run/warm/1.request'))->toBe('/run/warm/1.request.writing');
});
