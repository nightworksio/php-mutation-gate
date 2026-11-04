<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('lays its settings beneath the Pest runner\'s options, where the runner\'s own win', function (): void {
    $pest = Pest::of(patch: true, canary: Group::named('smoke'));

    expect($pest->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "smoke", "bootTail": false}')))
        ->and($pest->beneath(Choice::of('pest', Configs::options('{"canary": "own", "tests": ["spec"]}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "own", "bootTail": false, "tests": ["spec"]}')))
        ->and(Pest::none()->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary", "bootTail": false}')));
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
        ->toEqual(Choice::of('pest', Configs::options('{"patch": true, "canary": "mutation-canary", "bootTail": true}')));
});
