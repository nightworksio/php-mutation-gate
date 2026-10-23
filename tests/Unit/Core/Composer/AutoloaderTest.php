<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\Autoloader;
use NightWorksIO\MutationGate\Core\File\Path;

it('is autoload.php in the vendor directory Composer installs into', function (): void {
    expect(Autoloader::fileIn(Path::of('vendor')))->toEqual(Path::of('vendor/autoload.php'))
        ->and(Autoloader::fileIn(Path::of('../lib/vendor')))->toEqual(Path::of('../lib/vendor/autoload.php'));
});
