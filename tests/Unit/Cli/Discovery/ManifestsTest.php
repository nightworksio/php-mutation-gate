<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Discovery\Declared;
use NightWorksIO\MutationGate\Cli\Discovery\Manifests;
use NightWorksIO\MutationGate\Core\CannotJudge;

it('reads the extensions the root composer.json names, as its package', function (): void {
    $json = '{"name": "acme/app", "extra": {"mutation-gate": {"extensions": ["Acme\\\\One", "Acme\\\\Two"]}}}';

    expect(Manifests::root($json, 'composer.json'))->toEqual([new Declared('acme/app', 'Acme\\One'), new Declared('acme/app', 'Acme\\Two')]);
});

it('says a root without a name as its file', function (): void {
    expect(Manifests::root('{"extra": {"mutation-gate": {"extensions": ["Acme\\\\One"]}}}', 'composer.json'))->toEqual([new Declared('composer.json', 'Acme\\One')])
        ->and(Manifests::root('{"name": 7, "extra": {"mutation-gate": {"extensions": ["Acme\\\\One"]}}}', 'composer.json'))->toEqual([new Declared('composer.json', 'Acme\\One')]);
});

it('reads no extension where a manifest names none', function (string $json): void {
    expect(Manifests::root($json, 'composer.json'))->toBe([]);
})->with(['{}', '[]', '{"extra": []}', '{"extra": "none"}', '{"extra": {"mutation-gate": {}}}']);

it('cannot judge a root that is not a JSON object', function (string $json): void {
    expect(Manifests::root($json, 'composer.json'))->toEqual(CannotJudge::because('composer.json is not a JSON object.'));
})->with(['', '"acme/app"', '{"name": ']);

it('cannot judge extensions that are not a list of class names', function (string $extensions): void {
    expect(Manifests::root(sprintf('{"name": "acme/app", "extra": {"mutation-gate": {"extensions": %s}}}', $extensions), 'composer.json'))
        ->toEqual(CannotJudge::because('acme/app names extra.mutation-gate.extensions, and it is not a list of class names.'));
})->with(['"Acme\\\\One"', '{"one": "Acme\\\\One"}', '["Acme\\\\One", 2]', '[["Acme\\\\One"]]']);

it('reads the extensions every installed package names, in order', function (): void {
    $json = '{"packages": [{"name": "acme/one", "extra": {"mutation-gate": {"extensions": ["Acme\\\\One"]}}}, {"name": "acme/plain"}, "not a package", {"name": "acme/two", "extra": {"mutation-gate": {"extensions": ["Acme\\\\Two", "Acme\\\\Three"]}}}], "dev": true}';

    expect(Manifests::installed($json, 'vendor/composer/installed.json'))->toEqual([
        new Declared('acme/one', 'Acme\\One'),
        new Declared('acme/two', 'Acme\\Two'),
        new Declared('acme/two', 'Acme\\Three'),
    ]);
});

it('cannot judge an installed.json that is not Composer 2\'s list of packages', function (string $json): void {
    expect(Manifests::installed($json, 'vendor/composer/installed.json'))
        ->toEqual(CannotJudge::because('vendor/composer/installed.json is not the list of installed packages Composer 2 writes, so what it installed cannot be read.'));
})->with(['', '[{"name": "acme/one"}]', '{"dev": true}', '{"packages": {"acme/one": {}}}', '{"packages": "none"}']);

it('cannot judge an installed package whose extensions are not a list of class names', function (): void {
    $json = '{"packages": [{"name": "acme/one", "extra": {"mutation-gate": {"extensions": ["Acme\\\\One"]}}}, {"name": "acme/two", "extra": {"mutation-gate": {"extensions": "Acme\\\\Two"}}}]}';

    expect(Manifests::installed($json, 'vendor/composer/installed.json'))
        ->toEqual(CannotJudge::because('acme/two names extra.mutation-gate.extensions, and it is not a list of class names.'));
});
