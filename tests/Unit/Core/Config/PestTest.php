<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('lays its settings beneath the Pest runner\'s options, where the runner\'s own win', function (): void {
    $pest = Pest::of(patch: true, canary: Group::named('smoke'));

    expect($pest->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "smoke", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')))
        ->and($pest->beneath(Choice::of('pest', Configs::options('{"canary": "own", "tests": ["spec"]}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "own", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}, "tests": ["spec"]}')))
        ->and(Pest::none()->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')));
});

it('leaves any other runner\'s options as they are', function (): void {
    expect(Pest::of(patch: true)->beneath(Choice::of('infection', Configs::options('{"x": 1}'))))
        ->toEqual(Choice::of('infection', Configs::options('{"x": 1}')));
});

it('boots the tail\'s workers only where pest.bootTail says so, a later layer winning, and writes what it was given', function (): void {
    $booting = Pest::of(patch: true, bootTail: true);

    expect([Pest::none()->bootTail(), $booting->bootTail(), $booting->over(Pest::of(bootTail: false))->bootTail(), $booting->over(Pest::of())->bootTail()])
        ->toBe([false, true, false, true])
        ->and($booting->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "mutation-canary", "bootTail": true, "workerOpcache": true, "schemata": true, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')));
});

it('writes pest.bootTail as the PHP config calls it, and nothing where it is not set', function (): void {
    expect([
        Pest::of(bootTail: true)->php(ProjectRoot::origin()),
        Pest::of(bootTail: false)->php(ProjectRoot::origin()),
        Pest::of(patch: true)->php(ProjectRoot::origin()),
    ])->toEqual([
        PhpCalls::inWith('Pest::bootingTail()'),
        PhpCalls::inWith('Pest::tailUnbooted()'),
        PhpCalls::inWith('Pest::patched()'),
    ]);
});

it('caches in the warm workers unless pest.workerOpcache turns it off, a later layer winning', function (): void {
    $off = Pest::of(workerOpcache: false);

    expect([Pest::none()->workerOpcache(), $off->workerOpcache(), $off->over(Pest::of(workerOpcache: true))->workerOpcache(), $off->over(Pest::of())->workerOpcache()])
        ->toBe([true, false, true, false])
        ->and($off->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary", "bootTail": false, "workerOpcache": false, "schemata": true, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')));
});

it('writes pest.workerOpcache as the PHP config calls it, and nothing where it is not set', function (): void {
    expect([
        Pest::of(workerOpcache: true)->php(ProjectRoot::origin()),
        Pest::of(workerOpcache: false)->php(ProjectRoot::origin()),
        Pest::of(patch: false, bootTail: true, workerOpcache: false)->php(ProjectRoot::origin()),
        Pest::none()->php(ProjectRoot::origin()),
    ])->toEqual([
        PhpCalls::inWith('Pest::cachingInWorkers()'),
        PhpCalls::inWith('Pest::uncachedInWorkers()'),
        PhpCalls::inWith('Pest::unpatched()', 'Pest::bootingTail()', 'Pest::uncachedInWorkers()'),
        PhpCalls::inWith(),
    ]);
});

it('compiles schemata unless pest.schemata turns them off, a later layer winning, and writes it as the PHP config calls it', function (): void {
    $off = Pest::of(schemata: false);

    expect([Pest::none()->schemata(), $off->schemata(), $off->over(Pest::of(schemata: true))->schemata(), $off->over(Pest::of())->schemata()])
        ->toBe([true, false, true, false])
        ->and($off->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary", "bootTail": false, "workerOpcache": true, "schemata": false, "bootMutants": true, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')))
        ->and([Pest::of(schemata: true)->php(ProjectRoot::origin()), $off->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Pest::compilingSchemata()'), PhpCalls::inWith('Pest::withoutSchemata()')]);
});

it('boots mutants\' workers unless pest.bootMutants turns it off, a later layer winning, and writes it as the PHP config calls it', function (): void {
    $off = Pest::of(bootMutants: false);

    expect([Pest::none()->bootMutants(), $off->bootMutants(), $off->over(Pest::of(bootMutants: true))->bootMutants(), $off->over(Pest::of())->bootMutants()])
        ->toBe([true, false, true, false])
        ->and($off->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": false, "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true}}')))
        ->and([Pest::of(bootMutants: true)->php(ProjectRoot::origin()), $off->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Pest::bootingMutants()'), PhpCalls::inWith('Pest::mutantsUnbooted()')]);
});
