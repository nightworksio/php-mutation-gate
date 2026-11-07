<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Proof\TimingWeight;
use NightWorksIO\MutationGate\Core\Runner\RunnerPace;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A setting of a config that names the runner. */
$settings = static fn(array $config = []): Settings => Configs::settings(['runner' => 'pest', ...$config]);

it('reads how a run plans its work and learns what it costs, each key at its default where no layer sets it', function () use ($settings): void {
    $standard = $settings()->shards()->planning();
    $set = $settings([
        'shards' => ['cutAt' => 3.5],
        'plan' => ['chunkMinutes' => 30, 'provingRange' => 5],
        'timing' => ['newest' => 0.5, 'startUpSamples' => 7],
    ])->shards()->planning();

    expect($standard->chunk())->toEqual(Seconds::minutes(15))
        ->and($standard->provingRange()->farthest())->toBe(20)
        ->and($standard->cutAt())->toBe(2.0)
        ->and($standard->newest())->toEqual(TimingWeight::of(0.9))
        ->and($standard->startUpSamples()->runs())->toBe(3)
        ->and($set->chunk())->toEqual(Seconds::minutes(30))
        ->and($set->provingRange()->farthest())->toBe(5)
        ->and($set->cutAt())->toBe(3.5)
        ->and($set->newest())->toEqual(TimingWeight::of(0.5))
        ->and($set->startUpSamples()->runs())->toBe(7);
});

it('reads how a runner paces its work, each key at its default where no layer sets it', function () use ($settings): void {
    $standard = $settings()->runner();
    $set = $settings(['runner' => ['use' => 'pest', 'recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05]])->runner();

    expect($standard->pace())->toEqual(RunnerPace::standard())
        ->and($standard->poll())->toEqual(Seconds::of(0.01))
        ->and($set->pace())->toEqual(RunnerPace::of(8, Seconds::minutes(2)))
        ->and($set->poll())->toEqual(Seconds::of(0.05));
});

it('reads the remaining keys that tune a run, each at its default where no layer sets it', function () use ($settings): void {
    $standard = $settings();
    $set = $settings([
        'timeouts' => ['startUp' => '9s'],
        'staticCheck' => ['opcache' => ['batch' => 50, 'limit' => '30s']],
        'ignores' => ['notice' => 7],
        'local' => ['watchPoll' => '5s'],
        'triage' => ['fewest' => 3],
    ]);

    expect($standard->triage()->bounds()->unmeasuredStartUp())->toEqual(Seconds::of(5.0))
        ->and($standard->staticCheck()->opcacheBatch())->toBe(100)
        ->and($standard->staticCheck()->opcacheLimit())->toEqual(Seconds::of(60.0))
        ->and($standard->ignores()->notice())->toBe(14)
        ->and($standard->local()->watchPoll())->toEqual(Seconds::of(1.0))
        ->and($standard->local()->triageFewest())->toBe(2)
        ->and($set->triage()->bounds()->unmeasuredStartUp())->toEqual(Seconds::of(9.0))
        ->and($set->staticCheck()->opcacheBatch())->toBe(50)
        ->and($set->staticCheck()->opcacheLimit())->toEqual(Seconds::of(30.0))
        ->and($set->ignores()->notice())->toBe(7)
        ->and($set->local()->watchPoll())->toEqual(Seconds::of(5.0))
        ->and($set->local()->triageFewest())->toBe(3);
});

it('lays a later layer\'s tuning over an earlier one, key by key', function (): void {
    $laid = Configs::valid(['plan' => ['chunkMinutes' => 30, 'provingRange' => 5], 'timing' => ['newest' => 0.5], 'runner' => ['recycleAfter' => 8, 'poll' => 0.05]])
        ->over(Configs::valid(['plan' => ['provingRange' => 6], 'timing' => ['startUpSamples' => 7], 'shards' => ['cutAt' => 1], 'runner' => ['warmSlack' => '2m', 'poll' => 0.1]]));

    expect(Configs::decoded($laid))->toMatchArray([
        'plan' => ['chunkMinutes' => 30, 'provingRange' => 6],
        'timing' => ['newest' => 0.5, 'startUpSamples' => 7],
        'shards' => ['cutAt' => 1],
        'runner' => ['recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.1],
    ]);
});

it('writes each key into a config written as PHP, through the builder that reads it back', function (array $config, string $call): void {
    expect(Configs::valid(['runner' => 'pest', ...$config])->php(ProjectRoot::origin())->code())->toContain($call);
})->with([
    'shards.cutAt' => [['shards' => ['cutAt' => 3.5]], 'Shards::cutAt(3.5)'],
    'plan.chunkMinutes' => [['plan' => ['chunkMinutes' => 30]], 'Plan::chunkMinutes(30)'],
    'plan.provingRange' => [['plan' => ['provingRange' => 5]], 'Plan::provingRange(5)'],
    'timing.newest' => [['timing' => ['newest' => 0.5]], 'Timing::newest(0.5)'],
    'timing.startUpSamples' => [['timing' => ['startUpSamples' => 7]], 'Timing::startUpSamples(7)'],
    'timeouts.startUp' => [['timeouts' => ['startUp' => '9s']], "Timeouts::startUp('9s')"],
    'staticCheck.opcache.batch' => [['staticCheck' => ['opcache' => ['batch' => 50]]], 'StaticCheck::opcacheBatch(50)'],
    'staticCheck.opcache.limit' => [['staticCheck' => ['opcache' => ['limit' => '30s']]], "StaticCheck::opcacheLimit('30s')"],
    'ignores.notice' => [['ignores' => ['notice' => 7]], 'Ignores::notice(7)'],
    'local.watchPoll' => [['local' => ['watchPoll' => '5s']], "Local::watchPoll('5s')"],
    'triage.fewest' => [['triage' => ['fewest' => 3]], 'Local::triageFewest(3)'],
]);

it('writes a runner\'s pace on the runner it chooses, and on the gate where it chooses none', function (array $runner, string $call): void {
    expect(Configs::valid(['runner' => $runner])->php(ProjectRoot::origin())->code())->toContain($call);
})->with([
    'choosing one' => [
        ['use' => 'pest', 'recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05],
        "Runner::pest()->paced(Pace::of(recycleAfter: 8, warmSlack: '2m', poll: 0.05))",
    ],
    'choosing none' => [
        ['recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05],
        "Pace::of(recycleAfter: 8, warmSlack: '2m', poll: 0.05)",
    ],
    'only some' => [['use' => 'pest', 'poll' => 1], 'Runner::pest()->paced(Pace::of(poll: 1.0))'],
]);

it('refuses a tuning key out of its range', function (array $config, string $problem): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', ...$config])))->toBe([$problem]);
})->with([
    'no minutes in a chunk' => [['plan' => ['chunkMinutes' => 0]], 'plan.chunkMinutes: expected an integer of at least 1, got 0'],
    'a proving range below none' => [['plan' => ['provingRange' => -1]], 'plan.provingRange: expected an integer of at least 0, got -1'],
    'a cut below none' => [['shards' => ['cutAt' => -1]], 'shards.cutAt: expected a number of at least 0, got -1'],
    'a newest weight of none' => [['timing' => ['newest' => 0]], 'timing.newest: expected a number above 0 and at most 1, got 0'],
    'a newest weight past one' => [['timing' => ['newest' => 1.5]], 'timing.newest: expected a number above 0 and at most 1, got 1.5'],
    'no start-up samples' => [['timing' => ['startUpSamples' => 0]], 'timing.startUpSamples: expected an integer of at least 1, got 0'],
    'no programs a child' => [['staticCheck' => ['opcache' => ['batch' => 0]]], 'staticCheck.opcache.batch: expected an integer of at least 1, got 0'],
    'one run that cannot disagree' => [['triage' => ['fewest' => 1]], 'triage.fewest: expected an integer of at least 2, got 1'],
    'a notice below none' => [['ignores' => ['notice' => -1]], 'ignores.notice: expected an integer of at least 0, got -1'],
    'a pace of no runs' => [['runner' => ['use' => 'pest', 'recycleAfter' => 0]], 'runner.recycleAfter: expected an integer of at least 1, got 0'],
    'a poll of no time' => [['runner' => ['use' => 'pest', 'poll' => 0]], 'runner.poll: expected a number above 0 and at most 60, got 0'],
]);

it('holds the values no config can write to what they must be', function (): void {
    expect(StartUpSamples::of(0)->runs())->toBe(1)
        ->and(RunnerPace::of(0, Seconds::of(1.0))->recycleAfter())->toBe(1)
        ->and(Local::of(triageFewest: 1)->triageFewest())->toBe(2);
});
