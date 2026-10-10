<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;

it('says why the helper answers nothing', function (): void {
    expect(NotAccelerated::because('turned off')->why())->toBe('turned off');
});
