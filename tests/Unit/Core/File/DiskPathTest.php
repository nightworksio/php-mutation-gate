<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\DiskPath;

it('names an entry inside a directory on disk', function (): void {
    expect(DiskPath::of('/srv/app')->child('src')->value())->toBe('/srv/app/src')
        ->and(DiskPath::of('/srv/app/')->child('src')->value())->toBe('/srv/app/src')
        ->and(DiskPath::of('/')->child('etc')->value())->toBe('/etc');
});
