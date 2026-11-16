<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\Processes;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

it('holds every process the gate starts in a session and a cgroup where it can, under 85% of the memory and 8192 processes, where no layer says', function (Processes $processes): void {
    expect([$processes->group(), $processes->contain(), $processes->memory(), $processes->pids()])
        ->toBe([ProcessHold::Auto, ProcessHold::Auto, 85, 8192]);
})->with([
    'none' => [fn(): Processes => Processes::none()],
    'standard' => [fn(): Processes => Processes::standard()],
]);

it('takes each setting from the latest layer that says it', function (): void {
    $laid = Processes::of(ProcessHold::Require, ProcessHold::Off, 50, 100)
        ->over(Processes::of(contain: ProcessHold::Require, pids: 8))
        ->over(Processes::none());

    expect([$laid->group(), $laid->contain(), $laid->memory(), $laid->pids()])
        ->toBe([ProcessHold::Require, ProcessHold::Require, 50, 8]);
});

it('stays as it is under a part of another kind', function (): void {
    expect(Processes::of(memory: 40)->over(Shards::standard()))->toEqual(Processes::of(memory: 40));
});

it('writes each setting it holds, and nothing where it holds none', function (): void {
    expect(Processes::of(ProcessHold::Off, ProcessHold::Require, 70, 2048)->written(ProjectRoot::origin()))
        ->toEqual(Json::object(Member::of('processes', Json::object(
            Member::of('group', 'off'),
            Member::of('contain', 'require'),
            Member::of('memory', 70),
            Member::of('pids', 2048),
        ))))
        ->and(Processes::none()->written(ProjectRoot::origin()))->toEqual(Json::object());
});

it('writes each setting it holds as the PHP config calls it', function (): void {
    expect(Processes::of(ProcessHold::Off, ProcessHold::Require, 70, 2048)->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith(
        "Processes::group('off')",
        "Processes::contain('require')",
        'Processes::memory(70)',
        'Processes::pids(2048)',
    ))->and(Processes::none()->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith())
        ->and(Processes::of(memory: new Absent())->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith());
});

it('holds what it starts unless it is off', function (ProcessHold $hold, bool $holds): void {
    expect($hold->holds())->toBe($holds);
})->with([
    'auto' => [fn(): ProcessHold => ProcessHold::Auto, true],
    'require' => [fn(): ProcessHold => ProcessHold::Require, true],
    'off' => [fn(): ProcessHold => ProcessHold::Off, false],
]);
