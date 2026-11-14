<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Watch\Unwatched;

it('says why no file is watched', function (): void {
    expect(Unwatched::because('inotify has no watch left')->why())->toBe('inotify has no watch left');
});
