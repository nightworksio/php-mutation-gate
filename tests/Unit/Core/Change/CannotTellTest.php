<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;

it('says why version control cannot tell', function (): void {
    expect(CannotTell::because('This is not a git repository.')->why())->toBe('This is not a git repository.');
});
