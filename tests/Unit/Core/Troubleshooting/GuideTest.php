<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

const GUIDE_AT = 'https://github.com/nightworksio/php-mutation-gate/blob/%s/.docs/guide/troubleshooting.md#no-tree';

it('links a slug into the guide of the release Composer installed, or of main for anything else', function (string $installed, string $ref): void {
    expect(Guide::ofInstalled($installed)->link(Slug::NoTree))->toBe(sprintf(GUIDE_AT, $ref));
})->with([
    'a tag with its v' => ['v1.4.2', 'v1.4.2'],
    'a version without it' => ['1.4.2', 'v1.4.2'],
    'a pre-release' => ['2.0.0-rc.1', 'v2.0.0-rc.1'],
    'a branch' => ['dev-main', 'main'],
    'a partial version' => ['1.4', 'main'],
]);

it('reads the gate\'s version from what Composer installed, and main where it lists no gate', function (): void {
    $installed = static fn(string $json): Installed|CannotJudge => Installed::decode(Contents::of($json), Path::of('vendor/composer/installed.json'));
    $listed = $installed('{"packages": [{"name": "nightworksio/mutation-gate", "version": "1.2.0"}]}');
    $unlisted = $installed('{"packages": [{"name": "pestphp/pest", "version": "4.0.0"}]}');

    expect($listed instanceof Installed ? Guide::installedIn($listed)->link(Slug::NoTree) : '')->toBe(sprintf(GUIDE_AT, 'v1.2.0'))
        ->and($unlisted instanceof Installed ? Guide::installedIn($unlisted)->link(Slug::NoTree) : '')->toBe(sprintf(GUIDE_AT, 'main'))
        ->and(Guide::unreleased()->link(Slug::NoTree))->toBe(sprintf(GUIDE_AT, 'main'));
});
