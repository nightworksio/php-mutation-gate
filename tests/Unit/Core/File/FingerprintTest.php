<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Path;

it('pairs a file with the digest of what it holds', function (): void {
    $fingerprint = Fingerprint::of(Path::of('src/Money.php'), Digest::of('9daeaf'));

    expect($fingerprint->path()->value())->toBe('src/Money.php')
        ->and($fingerprint->digest()->value())->toBe('9daeaf');
});
