<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

/** What Composer installed, as a list of every shape of entry. */
function installedFixture(): Installed
{
    $installed = Installed::decode(Contents::of((string) json_encode(['packages' => [
        ['name' => 'a/one', 'version' => '1.0.0', 'source' => ['reference' => 's1'], 'dist' => ['reference' => 'd1']],
        ['name' => 'b/two', 'version' => '2.0.0', 'source' => ['reference' => ''], 'dist' => ['reference' => 'd2']],
        ['name' => 'c/three', 'version' => 3, 'source' => 'not a source'],
        ['name' => ['not a name'], 'version' => '9'],
        'not a package',
        [],
    ]])), Path::of('vendor/composer/installed.json'));

    return $installed instanceof Installed ? $installed : throw new RuntimeException('The fixture is not a list.');
}

it('names each package\'s version and its source reference, or its dist one without a source one', function (): void {
    expect(installedFixture()->versionsOf('b/two', 'a/one', 'c/three'))->toEqual(Versions::of(
        Version::of('b/two', '2.0.0', 'd2'),
        Version::of('a/one', '1.0.0', 's1'),
        Version::of('c/three', '', ''),
    ));
});

it('names the packages it does not list, and leaves them out of the versions', function (): void {
    expect(installedFixture()->missing('a/one', 'z/none', 'y/none'))->toBe(['z/none', 'y/none'])
        ->and(installedFixture()->versionsOf('z/none', 'a/one'))->toEqual(Versions::of(Version::of('a/one', '1.0.0', 's1')))
        ->and(installedFixture()->has('a/one'))->toBeTrue()
        ->and(installedFixture()->has('z/none'))->toBeFalse();
});

it('holds the manifest of every entry that is a map, named or not, in its order', function (): void {
    $origins = array_map(static fn(Manifest $package): string => $package->origin(), [...installedFixture()]);

    expect($origins)->toBe(['a/one', 'b/two', 'c/three', 'vendor/composer/installed.json']);
});

it('answers the versions of every package a runner drives, or why it cannot say which runner judges', function (): void {
    expect(installedFixture()->drivenBy('Fake', 'a/one', 'b/two'))->toEqual(Versions::of(
        Version::of('a/one', '1.0.0', 's1'),
        Version::of('b/two', '2.0.0', 'd2'),
    ))
        ->and(installedFixture()->drivenBy('Fake', 'a/one', 'z/none', 'y/none'))->toEqual(CannotJudge::because(
            'vendor/composer/installed.json does not list z/none, y/none, so the gate cannot say which Fake judges the mutants. '
            . 'Run composer install.',
        ));
});

it('lists nothing where there is no file', function (): void {
    $installed = Installed::missingAt(Path::of('vendor/composer/installed.json'));

    expect($installed->missing('a/one'))->toBe(['a/one'])
        ->and([...$installed])->toBe([]);
});

it('cannot judge text that is not JSON, or that holds no list of packages', function (string $text): void {
    expect(Installed::decode(Contents::of($text), Path::of('vendor/composer/installed.json')))->toEqual(CannotJudge::because(
        'vendor/composer/installed.json is not the list of installed packages Composer 2 writes, so what it installed cannot be read.',
    ));
})->with(['{', '', '{"packages": "none"}', '{"packages": {"a": {}}}', '[]']);
