<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

it('is the runner, the versions it drives and the PHP it runs on', function (): void {
    $versions = Versions::of(Version::of('pestphp/pest', '5.2.1', 'c'));
    $identity = Identity::of('pest', $versions, Digest::of('8.5.7-pcov'));

    expect($identity->runner())->toBe('pest')
        ->and($identity->versions())->toBe($versions)
        ->and($identity->platform()->value())->toBe('8.5.7-pcov');
});
