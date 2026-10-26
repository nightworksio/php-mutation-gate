<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\CompanionRead;

it('says why an object could not be read in one sentence, whether or not the store ends its own with a stop', function (
    string $why,
): void {
    expect(CompanionRead::unread(Companion::Coverage, 'refs/heads/main', $why)->why())
        ->toBe('The kept coverage map at refs/heads/main could not be read: no answer came in time.');
})->with(['ended with a stop' => 'no answer came in time.', 'with none' => 'no answer came in time']);
