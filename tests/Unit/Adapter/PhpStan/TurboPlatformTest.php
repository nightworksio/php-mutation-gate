<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\TurboPlatform;
use NightWorksIO\MutationGate\Core\Local\System;

it('names the platform PHPStan builds its turbo extension for by the machine, its OS family and its C library', function (string $uname, System $system, bool $musl, string $platform): void {
    expect(TurboPlatform::named($uname, $system, $musl))->toBe($platform);
})->with([
    'an Intel Linux on glibc' => ['x86_64', System::Linux, false, 'linux-gnu-x86_64'],
    'an amd64 Linux on musl' => ['AMD64', System::Linux, true, 'linux-musl-x86_64'],
    'an ARM Linux' => ['aarch64', System::Linux, false, 'linux-gnu-arm64'],
    'an ARM Mac' => ['arm64', System::Darwin, false, 'macos-arm64'],
    'an Intel Mac' => ['x86_64', System::Darwin, false, ''],
    'an Intel Windows' => ['AMD64', System::Windows, false, 'windows-x86_64'],
    'an ARM Windows' => ['arm64', System::Windows, false, ''],
    'another system' => ['amd64', System::Other, false, ''],
    'another machine' => ['riscv64', System::Linux, false, ''],
]);
