<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\GateMethod;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

it('writes the phpunit tree source with no options by its builder method, with no fallback paths', function (): void {
    expect(Setup::of(treeSource: Choice::of('phpunit', Options::none()))->php(ProjectRoot::origin()))
        ->toEqual(PhpCalls::onGate(GateMethod::TreeSource, 'Source::phpunit()'));
});

it('writes the extensions, each preset by its own method or by name, and the tree source with its fallback paths', function (): void {
    $setup = Setup::of(
        Listed::of('Acme\\GateSlack\\SlackExtension'),
        Listed::of('laravel', 'library', 'acme'),
        treeSource: Choice::of('phpunit', Options::of(Json::object(Member::of('fallback', Json::items('app', 'lib'))))),
    );

    expect($setup->php(ProjectRoot::origin()))->toEqual(
        PhpCalls::onGate(GateMethod::Extensions, "Load::extension('Acme\\\\GateSlack\\\\SlackExtension')")
            ->and(PhpCalls::onGate(GateMethod::Preset, 'Preset::laravel()', 'Preset::library()', "Preset::named('acme')"))
            ->and(PhpCalls::onGate(GateMethod::TreeSource, "Source::phpunit('app', 'lib')")),
    );
});

it('writes no extensions or presets where their lists are empty, and the phpunit source by uses where it takes more than its fallback', function (): void {
    $source = Choice::of('phpunit', Options::of(Json::object(Member::of('fallback', Json::items('app')), Member::of('depth', 1))));

    expect([Setup::of(Listed::of(), Listed::of())->php(ProjectRoot::origin()), Setup::of(treeSource: $source)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::none(), PhpCalls::onGate(GateMethod::TreeSource, "Source::uses('phpunit', Option::list('fallback', 'app'), Option::of('depth', 1))")]);
});

it('writes one preset by its name, and more than one as a list', function (): void {
    expect([Setup::of(presets: Listed::of('laravel'))->written(ProjectRoot::origin())->line(), Setup::of(presets: Listed::of('laravel', 'acme'))->written(ProjectRoot::origin())->line()])
        ->toBe(['{"preset":"laravel"}', '{"preset":["laravel","acme"]}']);
});
