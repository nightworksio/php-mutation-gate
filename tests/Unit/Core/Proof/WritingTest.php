<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Writing;

it('is written in the config as auto or never', function (): void {
    expect(Writing::from('auto'))->toBe(Writing::Auto)
        ->and(Writing::from('never'))->toBe(Writing::Never);
});
