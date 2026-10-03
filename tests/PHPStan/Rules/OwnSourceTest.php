<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\OwnSource;

it('holds a file under src, but not a test or a dependency', function (): void {
    expect(OwnSource::holds('/repo/src/Core/A.php'))->toBeTrue()
        ->and(OwnSource::holds('/repo/plugins/default/src/A.php'))->toBeTrue()
        ->and(OwnSource::holds('/repo/phpstan/Rules/A.php'))->toBeFalse()
        ->and(OwnSource::holds('/repo/tests/src/A.php'))->toBeFalse()
        ->and(OwnSource::holds('/repo/vendor/acme/lib/src/A.php'))->toBeFalse();
});
