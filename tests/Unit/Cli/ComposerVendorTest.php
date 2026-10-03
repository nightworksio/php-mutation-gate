<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv('COMPOSER_VENDOR_DIR');
    putenv('COMPOSER_BIN_DIR');
    Scratch::sweep();
});

it('is vendor where the project has no manifest, or one that names no other directory', function (): void {
    $bare = Scratch::directory();
    $silent = Scratch::directory();
    Scratch::write($silent, 'composer.json', '{}');

    expect(ComposerVendor::of($bare))->toEqual(Path::of('vendor'))
        ->and(ComposerVendor::of($silent))->toEqual(Path::of('vendor'));
});

it('is the manifest\'s config.vendor-dir, unless COMPOSER_VENDOR_DIR names another', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"vendor-dir": "lib/vendor"}}');

    $declared = ComposerVendor::of($project);
    putenv('COMPOSER_VENDOR_DIR=');
    $empty = ComposerVendor::of($project);
    putenv('COMPOSER_VENDOR_DIR=deps/vendor');

    expect($declared)->toEqual(Path::of('lib/vendor'))
        ->and($empty)->toEqual(Path::of('lib/vendor'))
        ->and(ComposerVendor::of($project))->toEqual(Path::of('deps/vendor'));
});

it('is on disk inside the project, or where an absolute vendor-dir says', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"vendor-dir": "lib/vendor"}}');
    $elsewhere = Scratch::directory();
    Scratch::write($elsewhere, 'composer.json', '{"config": {"vendor-dir": "/opt/vendor"}}');

    expect(ComposerVendor::on($project))->toBe(sprintf('%s/lib/vendor', $project))
        ->and(ComposerVendor::on($elsewhere))->toBe('/opt/vendor');
});

it('links commands into bin in the vendor directory, unless config.bin-dir names another place', function (): void {
    $bare = Scratch::directory();
    $moved = Scratch::directory();
    Scratch::write($moved, 'composer.json', '{"config": {"vendor-dir": "lib/vendor"}}');
    $declared = Scratch::directory();
    Scratch::write($declared, 'composer.json', '{"config": {"vendor-dir": "lib/vendor", "bin-dir": "{$vendor-dir}/../bin"}}');

    expect(ComposerVendor::binaries($bare))->toEqual(Path::of('vendor/bin'))
        ->and(ComposerVendor::binaries($moved))->toEqual(Path::of('lib/vendor/bin'))
        ->and(ComposerVendor::binaries($declared))->toEqual(Path::of('lib/bin'));
});

it('links commands where COMPOSER_BIN_DIR says, reading the vendor directory into it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"bin-dir": "scripts"}}');

    putenv('COMPOSER_BIN_DIR=');
    $empty = ComposerVendor::binaries($project);
    putenv('COMPOSER_VENDOR_DIR=deps');
    putenv('COMPOSER_BIN_DIR={$vendor-dir}/tools');

    expect($empty)->toEqual(Path::of('scripts'))
        ->and(ComposerVendor::binaries($project))->toEqual(Path::of('deps/tools'));
});
