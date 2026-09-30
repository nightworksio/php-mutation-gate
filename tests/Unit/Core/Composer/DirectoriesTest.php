<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\Directories;
use NightWorksIO\MutationGate\Core\File\Path;

it('installs where vendor-dir says, or in vendor, and links where bin-dir says, as it says it', function (): void {
    expect(Directories::declared('lib/vendor', '{$vendor-dir}/../bin')->vendor())->toEqual(Path::of('lib/vendor'))
        ->and(Directories::declared('lib/vendor', '{$vendor-dir}/../bin')->bin())->toBe('{$vendor-dir}/../bin')
        ->and(Directories::declared('', '')->vendor())->toEqual(Path::of('vendor'))
        ->and(Directories::declared('', '')->bin())->toBe('');
});
