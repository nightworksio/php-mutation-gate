<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\MutateScript;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;

it('adds the script to the scripts a composer.json laid out as Composer writes it has', function (): void {
    $manifest = Contents::of("{\n    \"name\": \"acme/app\",\n    \"scripts\": {\n        \"test\": \"phpunit\"\n    }\n}\n");

    expect(MutateScript::addedTo($manifest))->toEqual(Contents::of(<<<'JSON'
        {
            "name": "acme/app",
            "scripts": {
                "test": "phpunit",
                "mutate": [
                    "Composer\\Config::disableProcessTimeout",
                    "mutation-gate"
                ]
            }
        }

        JSON));
});

it('adds nothing where anything else in the file would change, or the script is there', function (): void {
    $laidOutOtherwise = Contents::of("{\n  \"name\": \"acme/app\"\n}\n");
    $unended = Contents::of("{\n    \"name\": \"acme/app\"\n}");
    $there = Contents::of("{\n    \"scripts\": {\n        \"mutate\": \"mutation-gate\"\n    }\n}\n");
    $scriptsNoObject = Contents::of("{\n    \"scripts\": []\n}\n");
    $notJson = Contents::of('{');
    $list = Contents::of("[]\n");

    expect(array_map(MutateScript::addedTo(...), [$laidOutOtherwise, $unended, $there, $scriptsNoObject, $notJson, $list]))
        ->each->toEqual(NotGiven::value());
});

it('tells a composer.json that has the script from one that has none', function (): void {
    expect([
        MutateScript::isIn(Contents::of('{"scripts": {"mutate": "mutation-gate"}}')),
        MutateScript::isIn(Contents::of('{"scripts": {"test": "phpunit"}}')),
        MutateScript::isIn(Contents::of('{"scripts": "mutate"}')),
        MutateScript::isIn(Contents::of('{')),
    ])->toBe([true, false, false, false]);
});

it('says the script as the line that adds it', function (): void {
    expect(MutateScript::line())->toBe('"mutate": ["Composer\\\\Config::disableProcessTimeout","mutation-gate"]');
});
