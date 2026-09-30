<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\Composer\Unnamed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Floor;

/** The manifest of this text in a directory, which the test requires to be one. */
function aManifest(string $json, string $directory = '.'): Manifest
{
    $manifest = Manifest::decode(Contents::of($json), Path::of($directory));

    return $manifest instanceof Manifest ? $manifest : throw new RuntimeException('The fixture is not a manifest.');
}

it('is in the composer.json of its directory', function (): void {
    expect(Manifest::fileIn(Path::of('modules/billing')))->toEqual(Path::of('modules/billing/composer.json'))
        ->and(Manifest::fileIn(Path::root()))->toEqual(Path::of('composer.json'))
        ->and(aManifest('{}', 'modules/billing')->file())->toEqual(Path::of('modules/billing/composer.json'));
});

it('cannot judge a composer.json that is not a JSON object', function (string $json): void {
    expect(Manifest::decode(Contents::of($json), Path::of('modules/billing')))
        ->toEqual(CannotJudge::because('modules/billing/composer.json is not a JSON object.'));
})->with(['{', '"acme/app"', '']);

it('knows the name it declares, and is said as its file without one', function (): void {
    expect(aManifest('{"name": "acme/billing"}', 'modules/billing')->name())->toBe('acme/billing')
        ->and(aManifest('{"name": "acme/billing"}', 'modules/billing')->origin())->toBe('acme/billing')
        ->and(aManifest('{}')->name())->toEqual(Unnamed::package())
        ->and(aManifest('{"name": 1}', 'modules/billing')->name())->toEqual(Unnamed::package())
        ->and(aManifest('{"name": ""}', 'modules/billing')->origin())->toBe('modules/billing/composer.json');
});

it('installs its packages where config.vendor-dir says, or in vendor', function (): void {
    expect(aManifest('{"config": {"vendor-dir": "lib/vendor"}}')->vendorDirectory())->toEqual(Path::of('lib/vendor'))
        ->and(aManifest('{"config": {"vendor-dir": ""}}')->vendorDirectory())->toEqual(Path::of('vendor'))
        ->and(aManifest('{"config": {"vendor-dir": 1}}')->vendorDirectory())->toEqual(Path::of('vendor'))
        ->and(aManifest('{}')->vendorDirectory())->toEqual(Path::of('vendor'));
});

it('names every path its autoload names, spelt from the repository root, in its order', function (): void {
    $manifest = aManifest(<<<'JSON'
        {
            "autoload": {
                "psr-4": {"Billing\\": "src/", "Billing\\Tax\\": ["tax/", "rates/"], "Bad\\": 3},
                "psr-0": {"Legacy_": "legacy/"},
                "classmap": ["generated/", "src/"],
                "files": ["helpers.php"],
                "exclude-from-classmap": ["generated/old/"]
            },
            "autoload-dev": {"psr-4": {"Billing\\Tests\\": "tests/"}}
        }
        JSON, 'modules/billing');

    expect($manifest->autoloaded())->toEqual(Paths::of(
        Path::of('modules/billing/src'),
        Path::of('modules/billing/tax'),
        Path::of('modules/billing/rates'),
        Path::of('modules/billing/legacy'),
        Path::of('modules/billing/generated'),
        Path::of('modules/billing/helpers.php'),
    ))
        ->and(aManifest('{"autoload": {"psr-4": {"App\\\\": "app/"}}}')->autoloaded())->toEqual(Paths::of(Path::of('app')));
});

it('autoloads nothing where its autoload holds no paths', function (string $json): void {
    expect(aManifest($json)->autoloaded())->toEqual(Paths::none());
})->with(['{}', '{"autoload": "src"}', '{"autoload": {"psr-4": "src"}}', '{"autoload": {"classmap": [1, {"a": 2}]}}']);

it('names every package it requires, to run or to be developed', function (): void {
    $manifest = aManifest('{"require": {"php": "^8.5", "acme/core": "*"}, "require-dev": {"acme/testing": "*"}, "suggest": {"acme/extra": "x"}}');

    expect([...$manifest->requires()])->toBe(['php', 'acme/core', 'acme/testing'])
        ->and([...$manifest->requiresToRun()])->toBe(['php', 'acme/core'])
        ->and(aManifest('{"require": "acme/core"}')->requires())->toEqual(Names::of())
        ->and(aManifest('{"require": ["acme/core"]}')->requiresToRun())->toEqual(Names::of());
});

it('names the url of every path repository', function (): void {
    $manifest = aManifest(<<<'JSON'
        {
            "repositories": [
                {"type": "path", "url": "packages/*"},
                {"type": "vcs", "url": "https://github.com/acme/money"},
                {"type": "path", "url": 1},
                {"type": "path", "url": ""},
                {"type": "path"},
                "packages/clock",
                {"type": "path", "url": "libs/clock"}
            ]
        }
        JSON);

    expect($manifest->pathRepositories())->toEqual(Paths::of(Path::of('packages/*'), Path::of('libs/clock')))
        ->and(aManifest('{"repositories": {"type": "path", "url": "libs/clock"}}')->pathRepositories())->toEqual(Paths::none());
});

it('reads which path repositories copy their packages, with symlink false, rather than link them', function (): void {
    $manifest = aManifest(<<<'JSON'
        {
            "repositories": [
                {"type": "path", "url": "packages/*", "options": {"symlink": false}},
                {"type": "path", "url": "libs/linked", "options": {"symlink": true}},
                {"type": "path", "url": "libs/default"},
                {"type": "path", "url": "libs/odd", "options": {"symlink": "no"}},
                {"type": "vcs", "url": "https://github.com/acme/money", "options": {"symlink": false}},
                {"type": "path", "url": "libs/copied", "options": {"symlink": false}}
            ]
        }
        JSON);

    expect($manifest->mirroredRepositories())->toEqual(Paths::of(Path::of('packages/*'), Path::of('libs/copied')));
});

it('reads where its autoload and autoload-dev would load a class from, below its own directory', function (): void {
    $manifest = Manifest::decode(
        Contents::of('{"autoload": {"psr-4": {"Acme\\\\": "src/"}}, "autoload-dev": {"psr-4": {"Acme\\\\Tests\\\\": "tests/"}}}'),
        Path::of('packages/money'),
    );
    $files = $manifest instanceof Manifest ? $manifest->classLocations() : null;

    expect($files?->files('Acme\\Tests\\MoneyTest'))->toEqual(Paths::of(
        Path::of('packages/money/src/Tests/MoneyTest.php'),
        Path::of('packages/money/tests/MoneyTest.php'),
    ));
});

it('reads its extra.mutation-gate entry, which says it as its file', function (): void {
    expect(aManifest('{"extra": {"mutation-gate": {"floor": 90}}}')->gate()->floor())->toEqual(Floor::of(90))
        ->and(aManifest('{"extra": {"mutation-gate": {"extensions": 1}}}', 'modules/billing')->gate()->extensions())
        ->toEqual(CannotJudge::because('modules/billing/composer.json names extra.mutation-gate.extensions, and it is not a list of class names.'));
});

it('is an entry of what Composer installed, read from that list', function (): void {
    $manifest = Manifest::installed(Node::decode('{"name": "acme/billing", "autoload": {"psr-4": {"B\\\\": "src/"}}}'), Path::of('vendor/composer/installed.json'));

    expect($manifest->name())->toBe('acme/billing')
        ->and($manifest->file())->toEqual(Path::of('vendor/composer/installed.json'))
        ->and(Manifest::installed(Node::decode('{}'), Path::of('vendor/composer/installed.json'))->origin())
        ->toBe('vendor/composer/installed.json');
});

it('writes its text without the gate\'s entry, and every other member as it was read', function (): void {
    $manifest = aManifest('{"name": "acme/money", "extra": {"mutation-gate": {"floor": 90}, "laravel": {"providers": ["A\\\\B"]}}, "version": 1.0}');

    expect($manifest->withoutGateEntry())
        ->toEqual(Contents::of('{"name":"acme/money","extra":{"laravel":{"providers":["A\\\\B"]}},"version":1.0}'))
        ->and(aManifest('{"extra": "none", "0": true}')->withoutGateEntry())->toEqual(Contents::of('{"extra":"none","0":true}'))
        ->and(aManifest('[]')->withoutGateEntry())->toEqual(Contents::of('{}'));
});
