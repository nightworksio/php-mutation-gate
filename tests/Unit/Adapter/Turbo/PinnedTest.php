<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Pinned;
use NightWorksIO\MutationGate\Adapter\Turbo\Platform;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;

it('gives the digest pinned for a platform, and nothing for one with none', function (): void {
    $pins = Pinned::of(['linux-x86_64' => str_repeat('a', 64)]);

    expect($pins->digestOf(Platform::LinuxX64))->toEqual(Digest::of(str_repeat('a', 64)))
        ->and($pins->digestOf(Platform::MacArm64))->toBeInstanceOf(NotGiven::class);
});

it('pins a SHA-256, or nothing, for every platform in the gate\'s own table', function (Platform $platform): void {
    $pin = Pinned::release()->digestOf($platform);

    expect($pin instanceof NotGiven || Digest::isSha256($pin->value()))->toBeTrue();
})->with(Platform::cases());
