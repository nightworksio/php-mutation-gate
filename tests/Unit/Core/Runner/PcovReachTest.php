<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;

it('has pcov collect from the project\'s root and leave out its vendor directory', function (): void {
    expect(PcovReach::under('/project', Path::of('vendor'))->options())->toBe([
        '-d',
        'pcov.directory=/project',
        '-d',
        'pcov.exclude=~^/project/vendor/~',
    ]);
});

it('leaves out the vendor directory by the path pcov reads, wherever the project spells it', function (): void {
    expect(PcovReach::under('/fixture/library', Path::of('../vendor'))->options()[3])
        ->toBe('pcov.exclude=~^/fixture/vendor/~')
        ->and(PcovReach::under('/project', Path::of('/opt/vendor'))->options()[3])
        ->toBe('pcov.exclude=~^/opt/vendor/~');
});

it('leaves out only the vendor directory, whatever its path holds that a pattern reads', function (): void {
    $setting = PcovReach::under('/my~project (1.0)+', Path::of('vendor'))->options()[3];
    $pattern = substr($setting, strlen('pcov.exclude='));

    expect(preg_match($pattern, '/my~project (1.0)+/vendor/acme/Lib.php'))->toBe(1)
        ->and(preg_match($pattern, '/my~project (1x0)+/vendor/acme/Lib.php'))->toBe(0)
        ->and(preg_match($pattern, '/my~project (1.0)+/src/vendor/Lib.php'))->toBe(0)
        ->and(preg_match($pattern, '/my~project (1.0)+/vendored/Lib.php'))->toBe(0)
        ->and(preg_match($pattern, '/copy/my~project (1.0)+/vendor/acme/Lib.php'))->toBe(0);
});
