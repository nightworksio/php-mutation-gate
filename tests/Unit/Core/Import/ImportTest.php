<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;

it('lays each import over the ones before it, and says every key, the notes, and the keys to delete', function (): void {
    $import = Import::of(Layer::of(Setup::of(presets: Listed::of('library'))), Carried::imported('minMsi', 'the floor of every tree, 80.00'))
        ->and(Import::of(
            Layer::of(Setup::of(runner: Choice::of('infection', Json::object()))),
            Carried::imported('mutators.Plus.ignore', 'an ignore'),
            Carried::stays('mutators.Plus.ignore', 'a pattern stays'),
            Carried::dropped('maxTimeouts', 'timeouts are triaged'),
            Carried::stays('threads', 'the gate overrides it'),
        ))
        ->noting('phpunit.xml names lib.');

    expect($import->layer()->written(ProjectRoot::origin())->line())->toBe('{"preset":"library","runner":"infection"}')
        ->and($import->report('infection.json5'))->toBe(implode("\n", [
            'What became of each key of infection.json5:',
            '  minMsi: imported as the floor of every tree, 80.00',
            '  mutators.Plus.ignore: imported as an ignore',
            '  mutators.Plus.ignore: stays in infection.json5, because a pattern stays',
            '  maxTimeouts: dropped, because timeouts are triaged',
            '  threads: stays in infection.json5, because the gate overrides it',
            'phpunit.xml names lib.',
            'Delete these keys from infection.json5, since the gate no longer reads them there: minMsi, maxTimeouts.',
            'Run mutation-gate locally once.',
            'It writes the baseline at what the gate measures, and says if a tree is below the imported floor.',
        ]));
});

it('says no keys to delete where every key stays, and sets nothing where nothing was imported', function (): void {
    $import = Import::none()->and(Import::of(Layer::none(), Carried::stays('threads', 'the gate overrides it')));

    expect($import->layer())->toEqual(Layer::none())
        ->and($import->report('infection.json'))->toBe(implode("\n", [
            'What became of each key of infection.json:',
            '  threads: stays in infection.json, because the gate overrides it',
            'Run mutation-gate locally once.',
            'It writes the baseline at what the gate measures, and says if a tree is below the imported floor.',
        ]));
});
