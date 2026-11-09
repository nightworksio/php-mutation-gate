<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StreamSharding;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('is off, one child at once, checking changes, killers first, whole first passes and sharding by tests, where no layer sets it', function (): void {
    $none = SplitStream::of();

    expect([$none->enabled(), $none->children(), $none->changeCheck(), $none->killersFirst(), $none->sharding(), $none->killerStreams()])
        ->toBe([false, 1, true, true, StreamSharding::Tests, false]);
});

it('takes what a later layer sets, key by key', function (): void {
    $earlier = SplitStream::of(enabled: true, children: 4, changeCheck: false);
    $laid = $earlier->over(SplitStream::of(children: 2, killersFirst: false, sharding: StreamSharding::Source, killerStreams: true));

    expect([$laid->enabled(), $laid->children(), $laid->changeCheck(), $laid->killersFirst(), $laid->sharding(), $laid->killerStreams()])
        ->toBe([true, 2, false, false, StreamSharding::Source, true]);
});

it('writes the keys a layer sets, and nothing where it sets none', function (): void {
    expect(Json::object(SplitStream::of(enabled: true, sharding: StreamSharding::Source)->written())->line())
        ->toBe('{"splitStream":{"enabled":true,"sharding":"source"}}')
        ->and(Json::object(SplitStream::of()->written())->line())
        ->toBe('{}')
        ->and(Json::object(SplitStream::standard()->written())->line())
        ->toBe('{"splitStream":{"enabled":false,"children":1,"changeCheck":true,"killersFirst":true,"sharding":"tests","killerStreams":false}}');
});

it('writes each key set as the PHP config calls it', function (): void {
    expect([
        SplitStream::of(enabled: true, children: 3, changeCheck: true, killersFirst: true, sharding: StreamSharding::Tests, killerStreams: true)->php(),
        SplitStream::of(enabled: false, changeCheck: false, killersFirst: false, sharding: StreamSharding::Source, killerStreams: false)->php(),
        SplitStream::of()->php(),
    ])->toEqual([
        PhpCalls::inWith(
            'SplitStream::on()',
            'SplitStream::children(3)',
            'SplitStream::checkingChanges()',
            'SplitStream::killersFirst()',
            'SplitStream::shardingByTests()',
            'SplitStream::killerStreams()',
        ),
        PhpCalls::inWith(
            'SplitStream::off()',
            'SplitStream::forkingUnchecked()',
            'SplitStream::everyTestAlike()',
            'SplitStream::shardingBySource()',
            'SplitStream::wholeFirstPass()',
        ),
        PhpCalls::inWith(),
    ]);
});

it('is held by the Pest part, laid beneath the Pest runner\'s options and written beside pest', function (): void {
    $pest = Pest::of(patch: true)->over(Pest::splitting(SplitStream::of(enabled: true, children: 2)));

    expect($pest->splitStream()->enabled())->toBeTrue()
        ->and($pest->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "mutation-canary", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": true, "splitStream": {"enabled": true, "children": 2, "changeCheck": true, "killersFirst": true, "killerStreams": false}}')))
        ->and($pest->written(ProjectRoot::origin())->line())
        ->toBe('{"pest":{"patch":true},"splitStream":{"enabled":true,"children":2}}')
        ->and($pest->php(ProjectRoot::origin()))
        ->toEqual(PhpCalls::inWith('Pest::patched()', 'SplitStream::on()', 'SplitStream::children(2)'));
});

it('splits a run\'s streams only under the Pest runner, patched and compiling schemata, where it is enabled', function (): void {
    $on = Pest::of(patch: true)->over(Pest::splitting(SplitStream::of(enabled: true)));
    $pest = Choice::of('pest', Configs::options('{}'));

    expect([
        $on->splits($pest),
        $on->splits(Choice::of('infection', Configs::options('{}'))),
        $on->over(Pest::of(patch: false))->splits($pest),
        $on->over(Pest::of(schemata: false))->splits($pest),
        Pest::of(patch: true)->splits($pest),
    ])->toBe([true, false, false, false, false]);
});
