<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotWritten;

it('says why it wrote nothing', function (): void {
    expect(NotWritten::because('The token cannot comment on a fork.')->why())->toBe('The token cannot comment on a fork.');
});
