<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Adapter\Composer\Manifest;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Tests\Support\Project;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The manifest a directory of a project holding one `composer.json` of this text has. */
function manifestOf(string $json, string $directory = '.'): Manifest|Missing|CannotJudge
{
    return Manifest::in(Disk::at(Project::with([sprintf('%s/composer.json', $directory) => $json])), Path::of($directory));
}

/** The manifest of this text, which the test requires to be one. */
function aManifest(string $json, string $directory = '.'): Manifest
{
    $manifest = manifestOf($json, $directory);

    return $manifest instanceof Manifest ? $manifest : throw new RuntimeException('The fixture is not a manifest.');
}

it('is missing from a directory with no composer.json', function (): void {
    expect(Manifest::in(Disk::at(Project::with([])), Path::of('modules/billing')))->toEqual(Missing::at(Path::of('modules/billing/composer.json')));
});

it('cannot judge a composer.json that is not a JSON object', function (string $json): void {
    expect(manifestOf($json, 'modules/billing'))->toEqual(CannotJudge::because('modules/billing/composer.json is not a JSON object.'));
})->with(['{', '"acme/app"']);

it('knows the directory it is in and the name it declares', function (): void {
    $manifest = aManifest('{"name": "acme/billing"}', 'modules/billing');

    expect($manifest->directory())->toEqual(Path::of('modules/billing'))
        ->and($manifest->name())->toBe('acme/billing')
        ->and(aManifest('{}')->name())->toBe('')
        ->and(aManifest('{"name": 1}')->name())->toBe('');
});

it('installs its packages where config.vendor-dir says, or in vendor', function (): void {
    expect(aManifest('{"config": {"vendor-dir": "lib/vendor"}}')->vendorDirectory())->toEqual(Path::of('lib/vendor'))
        ->and(aManifest('{"config": {"vendor-dir": ""}}')->vendorDirectory())->toEqual(Path::of('vendor'))
        ->and(aManifest('{"config": {"vendor-dir": 1}}')->vendorDirectory())->toEqual(Path::of('vendor'))
        ->and(aManifest('{}')->vendorDirectory())->toEqual(Path::of('vendor'));
});

it('names every path its autoload names, spelt from the repository root', function (): void {
    $manifest = aManifest(<<<'JSON'
        {
            "autoload": {
                "psr-4": {"Billing\\": "src/", "Billing\\Tax\\": ["tax/", "rates/"]},
                "psr-0": {"Legacy_": "legacy/"},
                "classmap": ["generated/"],
                "files": ["helpers.php"]
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
        ->and(aManifest('{}')->autoloaded())->toEqual(Paths::none());
});

it('names every package it requires or requires for development', function (): void {
    $manifest = aManifest('{"require": {"php": "^8.5", "acme/core": "*"}, "require-dev": {"acme/testing": "*"}, "suggest": {"acme/extra": "x"}}');

    expect($manifest->requires())->toBe(['php', 'acme/core', 'acme/testing'])
        ->and(aManifest('{"require": "acme/core"}')->requires())->toBe([]);
});

it('names the url of every path repository', function (): void {
    $manifest = aManifest(<<<'JSON'
        {
            "repositories": [
                {"type": "path", "url": "packages/*"},
                {"type": "vcs", "url": "https://github.com/acme/money"},
                {"type": "path", "url": 1},
                {"type": "path"},
                "packages/clock",
                {"type": "path", "url": "libs/clock"}
            ]
        }
        JSON);

    expect($manifest->pathRepositories())->toBe(['packages/*', 'libs/clock'])
        ->and(aManifest('{}')->pathRepositories())->toBe([]);
});

it('declares the floor extra.mutation-gate.floor says', function (string $json, Floor|Exempt|Undeclared $floor): void {
    expect(aManifest($json)->floor())->toEqual($floor);
})->with([
    'none' => ['{}', Undeclared::floor()],
    'no mutation-gate object' => ['{"extra": {"mutation-gate": 1}}', Undeclared::floor()],
    'a whole number' => ['{"extra": {"mutation-gate": {"floor": 100}}}', Floor::of(100)],
    'a fraction' => ['{"extra": {"mutation-gate": {"floor": 83.41}}}', Floor::of(83.41)],
    'none at all, with its reason' => ['{"extra": {"mutation-gate": {"floor": 0, "floorReason": "Generated"}}}', Exempt::because('Generated')],
]);

it('cannot judge a floor that is no number from 0 to 100', function (string $json, string $said): void {
    expect(aManifest($json, 'modules/billing')->floor())->toEqual(CannotJudge::because($said));
})->with([
    'above 100' => ['{"extra": {"mutation-gate": {"floor": 100.5}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'below 0' => ['{"extra": {"mutation-gate": {"floor": -1}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'text' => ['{"extra": {"mutation-gate": {"floor": "90"}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'none at all without a reason' => [
        '{"extra": {"mutation-gate": {"floor": 0}}}',
        'modules/billing/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
    'none at all with an empty reason' => [
        '{"extra": {"mutation-gate": {"floor": 0.0, "floorReason": ""}}}',
        'modules/billing/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
]);

it('declares the floor extra.mutation-gate.newCodeFloor says for new lines', function (): void {
    expect(aManifest('{"extra": {"mutation-gate": {"newCodeFloor": 95}}}')->newCodeFloor())->toEqual(Floor::of(95))
        ->and(aManifest('{"extra": {"mutation-gate": {"newCodeFloor": 0}}}')->newCodeFloor())->toEqual(Floor::of(0))
        ->and(aManifest('{"extra": {"mutation-gate": {"floor": 95}}}')->newCodeFloor())->toEqual(Undeclared::floor())
        ->and(aManifest('{"extra": {"mutation-gate": {"newCodeFloor": true}}}', 'modules/billing')->newCodeFloor())
        ->toEqual(CannotJudge::because('modules/billing/composer.json: extra.mutation-gate.newCodeFloor is not a number from 0 to 100.'));
});
