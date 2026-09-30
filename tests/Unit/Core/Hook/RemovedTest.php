<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hook\Removed;

it('says which hook is gone', function (): void {
    expect(Removed::from('/repo/.git/hooks/pre-push')->said())->toBe('Removed /repo/.git/hooks/pre-push.');
});
