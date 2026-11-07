<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes the seconds a mutant may run and the most any may as the PHP config calls them', function (): void {
    expect(Triage::of(limit: Seconds::of(30), most: Seconds::of(600))->php(ProjectRoot::origin()))
        ->toEqual(PhpCalls::inWith('Timeouts::seconds(30)', 'Timeouts::most(600)'));
});

it('writes each test\'s own limit as the PHP config and the config file write it, and its absence as nothing', function (): void {
    $limited = Triage::of(perTest: 4, testFloor: Seconds::of(2));
    $off = Triage::of(perTest: false);

    expect($limited->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith('Timeouts::perTest(4)', 'Timeouts::testFloor(2)'))
        ->and($off->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith('Timeouts::noTestLimit()'))
        ->and(Triage::of()->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith())
        ->and($limited->bounds()->perTest())->toEqual(PerTestLimit::of(4, Seconds::of(2)))
        ->and($off->bounds()->perTest())->toEqual(PerTestLimit::off())
        ->and(Triage::of(testFloor: Seconds::of(5))->bounds()->perTest())->toEqual(PerTestLimit::of(3, Seconds::of(5)))
        ->and(Triage::of(perTest: 6)->bounds()->perTest())->toEqual(PerTestLimit::of(6, Seconds::of(3)));
});

it('takes each test\'s own limit from a later layer, and keeps the earlier where the later sets none', function (): void {
    $earlier = Triage::of(perTest: 4, testFloor: Seconds::of(2));

    expect($earlier->over(Triage::of(perTest: false))->bounds()->perTest())->toEqual(PerTestLimit::off())
        ->and($earlier->over(Triage::of())->bounds()->perTest())->toEqual(PerTestLimit::of(4, Seconds::of(2)))
        ->and($earlier->over(Triage::of(testFloor: Seconds::of(7)))->bounds()->perTest())->toEqual(PerTestLimit::of(4, Seconds::of(7)));
});

it('allows a kill\'s control 900 seconds unless set, or timeouts.most where longer, writes it as set, and refuses one under the most', function (): void {
    $set = Triage::of(controlMost: Seconds::of(1200.0));
    $refused = Triage::of(most: Seconds::of(400.0), controlMost: Seconds::of(350.0))->refusal();

    expect(Triage::none()->controlMost())->toEqual(Seconds::of(900.0))
        ->and(Triage::of(most: Seconds::of(1500.0))->controlMost())->toEqual(Seconds::of(1500.0))
        ->and(Triage::of(most: Seconds::of(1500.0))->refusal())->toEqual(Absent::setting())
        ->and(Triage::standard()->written(ProjectRoot::origin())->line())->not->toContain('controlMost')
        ->and($set->controlMost())->toEqual(Seconds::of(1200.0))
        ->and($set->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith('Timeouts::controlMost(1200)'))
        ->and($set->written(ProjectRoot::origin())->line())->toContain('"controlMost":1200')
        ->and(Configs::settings(['runner' => 'pest', 'timeouts' => ['controlMost' => 1200]])->triage()->controlMost())
        ->toEqual(Seconds::of(1200.0))
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'timeouts' => ['most' => 400, 'controlMost' => 350]])))
        ->toBe(['timeouts.controlMost: expected at least timeouts.most, 400, got 350'])
        ->and(Triage::of(most: Seconds::of(60.0))->over($set)->controlMost())->toEqual(Seconds::of(1200.0))
        ->and($refused instanceof Invalid ? $refused->said() : '')
        ->toBe('timeouts.controlMost: expected at least timeouts.most, 400, got 350');
});
