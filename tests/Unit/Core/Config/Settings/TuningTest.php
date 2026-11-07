<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Retained;
use NightWorksIO\MutationGate\Core\Config\RunnerPacing;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\TimingWeight;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
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

it('reads each key of a planning no layer set at its default', function (): void {
    $none = Planning::of();

    expect([$none->chunk(), $none->provingRange()->farthest(), $none->cutAt(), $none->newest(), $none->startUpSamples()->runs()])
        ->toEqual([Seconds::minutes(15), 20, 2.0, TimingWeight::of(0.9), 3]);
});

it('reads how a runner paces its work, each key at its default where no layer sets it', function () use ($settings): void {
    $standard = $settings()->runner();
    $set = $settings(['runner' => ['use' => 'pest', 'recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05, 'processes' => 3]])->runner();

    expect($standard->pace())->toEqual(RunnerPace::standard())
        ->and($standard->poll())->toEqual(Seconds::of(0.01))
        ->and($standard->processes(ProcessCount::of(8)))->toEqual(ProcessCount::of(8))
        ->and($set->pace())->toEqual(RunnerPace::of(8, Seconds::minutes(2)))
        ->and($set->poll())->toEqual(Seconds::of(0.05))
        ->and($set->processes(ProcessCount::of(8)))->toEqual(ProcessCount::of(3))
        ->and(RunnerPacing::of(processes: 1)->processes(ProcessCount::of(8)))->toEqual(ProcessCount::single())
        ->and(RunnerPacing::of(processes: 0)->processes(ProcessCount::of(8)))->toEqual(ProcessCount::of(8));
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

it('reads what a ledger keeps and how large what the gate reads may be, each at its default where no layer sets it', function () use ($settings): void {
    $standard = $settings()->proofs()->retained();
    $set = $settings(['proofs' => [
        'keep' => ['killers' => 100, 'bases' => 2, 'proofs' => 30, 'functions' => 40],
        'limits' => [
            'ledger' => ['packed' => 11, 'unpacked' => 12],
            'map' => ['packed' => 13, 'unpacked' => 14],
            'handoff' => ['packed' => 15, 'unpacked' => 16],
        ],
    ]])->proofs()->retained();

    expect([$standard->killers(), $standard->bases(), $standard->proofs(), $standard->functions()])->toBe([PHP_INT_MAX, 5, 20000, 5000])
        ->and([$standard->ledgerPacked(), $standard->ledgerUnpacked()])->toBe([11000000, 38000000])
        ->and([$standard->mapPacked(), $standard->mapUnpacked()])->toBe([4200000, 85000000])
        ->and($standard->handoffPacked())->toBe(256000000)
        ->and($standard->handoffUnpacked())->toBeInstanceOf(Absent::class)
        ->and([$set->killers(), $set->bases(), $set->proofs(), $set->functions()])->toBe([100, 2, 30, 40])
        ->and([$set->ledgerPacked(), $set->ledgerUnpacked()])->toBe([11, 12])
        ->and([$set->mapPacked(), $set->mapUnpacked()])->toBe([13, 14])
        ->and([$set->handoffPacked(), $set->handoffUnpacked()])->toBe([15, 16])
        ->and($settings(['proofs' => ['keep' => ['killers' => 'all']]])->proofs()->retained()->killers())->toBe(PHP_INT_MAX);
});

it('lays what a ledger keeps through the limits a store is given, and each store keeps to them', function (): void {
    $retained = Retained::of(killers: 3, bases: 1, proofs: 2, functions: 4, ledgerPacked: 5, ledgerUnpacked: 6, mapPacked: 7, mapUnpacked: 8);
    $limits = LedgerLimits::kept($retained);

    expect($limits->retention())->toEqual(LedgerRetention::kept($retained))
        ->and([$limits->packed(), $limits->unpacked()])->toBe([5, 6])
        ->and($limits->map())->toEqual(MapLimits::of(7, 8))
        ->and(MapLimits::kept($retained))->toEqual(MapLimits::of(7, 8))
        ->and(LedgerLimits::standard()->keeping(LedgerRetention::kept($retained))->retention())->toEqual(LedgerRetention::kept($retained));
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
    'proofs.keep.killers' => [['proofs' => ['keep' => ['killers' => 3]]], 'Retention::killers(3)'],
    'proofs.keep.killers, one' => [['proofs' => ['keep' => ['killers' => 1]]], 'Retention::killers(1)'],
    'proofs.keep.killers all' => [['proofs' => ['keep' => ['killers' => 'all']]], 'Retention::allKillers()'],
    'proofs.keep.bases' => [['proofs' => ['keep' => ['bases' => 2]]], 'Retention::bases(2)'],
    'proofs.keep.proofs' => [['proofs' => ['keep' => ['proofs' => 2]]], 'Retention::proofs(2)'],
    'proofs.keep.functions' => [['proofs' => ['keep' => ['functions' => 2]]], 'Retention::functions(2)'],
    'proofs.limits.ledger.packed' => [['proofs' => ['limits' => ['ledger' => ['packed' => 2]]]], 'Retention::ledgerPacked(2)'],
    'proofs.limits.ledger.unpacked' => [['proofs' => ['limits' => ['ledger' => ['unpacked' => 2]]]], 'Retention::ledgerUnpacked(2)'],
    'proofs.limits.map.packed' => [['proofs' => ['limits' => ['map' => ['packed' => 2]]]], 'Retention::mapPacked(2)'],
    'proofs.limits.map.unpacked' => [['proofs' => ['limits' => ['map' => ['unpacked' => 2]]]], 'Retention::mapUnpacked(2)'],
    'proofs.limits.handoff.packed' => [['proofs' => ['limits' => ['handoff' => ['packed' => 2]]]], 'Retention::handoffPacked(2)'],
    'proofs.limits.handoff.unpacked' => [['proofs' => ['limits' => ['handoff' => ['unpacked' => 2]]]], 'Retention::handoffUnpacked(2)'],
    'proofs.keep.kills' => [['proofs' => ['keep' => ['kills' => 7]]], 'Retention::kills(7)'],
    'proofs.keep.runs' => [['proofs' => ['keep' => ['runs' => 8]]], 'Retention::runs(8)'],
    'proofs.limits.read' => [['proofs' => ['limits' => ['read' => '45s']]], "Retention::read('45s')"],
    'timeouts.learned.percentile' => [['timeouts' => ['learned' => ['percentile' => 0.9]]], 'Timeouts::learnedPercentile(0.9)'],
    'timeouts.learned.least' => [['timeouts' => ['learned' => ['least' => 2.5]]], 'Timeouts::learnedLeast(2.5)'],
    'timeouts.learned.most' => [['timeouts' => ['learned' => ['most' => 8.5]]], 'Timeouts::learnedMost(8.5)'],
    'staticCheck.dependents' => [['staticCheck' => ['dependents' => 30]], 'StaticCheck::dependents(30)'],
    'staticCheck.preCheckAfter' => [['staticCheck' => ['preCheckAfter' => 40]], 'StaticCheck::preCheckAfter(40)'],
    'holds.fewestTests' => [['holds' => ['fewestTests' => 9]], 'Reach::hotPathFewestTests(9)'],
    'versionControl git, by name' => [['versionControl' => 'git'], 'VersionControl::git()'],
    'versionControl github, by name' => [['versionControl' => 'github'], 'VersionControl::github()'],
    'reach.lockMoves' => [['reach' => ['lockMoves' => 'loaded']], 'Reach::lockMoves(LockMoveReach::Loaded)'],
    'local.memoryHeadroom' => [['local' => ['memoryHeadroom' => 3]], 'Local::memoryHeadroom(3)'],
    'local.serveRequest' => [['local' => ['serveRequest' => '9s']], "Local::serveRequest('9s')"],
    'local.watchPoll' => [['local' => ['watchPoll' => '5s']], "Local::watchPoll('5s')"],
    'triage.fewest' => [['triage' => ['fewest' => 3]], 'Local::triageFewest(3)'],
]);

it('writes a runner\'s pace on the runner it chooses, and on the gate where it chooses none', function (array $runner, string $call): void {
    expect(Configs::valid(['runner' => $runner])->php(ProjectRoot::origin())->code())->toContain($call);
})->with([
    'choosing one' => [
        ['use' => 'pest', 'recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05, 'processes' => 3],
        "Runner::pest()->paced(Pace::of(recycleAfter: 8, warmSlack: '2m', poll: 0.05, processes: 3))",
    ],
    'choosing none' => [
        ['recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05],
        "Pace::of(recycleAfter: 8, warmSlack: '2m', poll: 0.05)",
    ],
    'only some' => [['use' => 'pest', 'poll' => 1], 'Runner::pest()->paced(Pace::of(poll: 1.0))'],
    'recycling alone' => [['use' => 'pest', 'recycleAfter' => 8], 'Runner::pest()->paced(Pace::of(recycleAfter: 8))'],
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
    'no bases kept' => [['proofs' => ['keep' => ['bases' => 0]]], 'proofs.keep.bases: expected an integer of at least 1, got 0'],
    'no killers kept' => [['proofs' => ['keep' => ['killers' => 0]]], 'proofs.keep.killers: expected an integer of at least 1, or "all", got 0'],
    'killers neither a count nor all' => [['proofs' => ['keep' => ['killers' => 'most']]], 'proofs.keep.killers: expected an integer of at least 1, or "all", got "most"'],
    'no bytes of a ledger' => [['proofs' => ['limits' => ['ledger' => ['packed' => 0]]]], 'proofs.limits.ledger.packed: expected an integer of at least 1, got 0'],
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

it('reads how a learned timeout multiple is read from the kills', function (): void {
    $learned = Configs::settings(['runner' => 'pest', 'timeouts' => ['learned' => ['percentile' => 0.9, 'least' => 3, 'most' => 8]]])->triage()->learned();
    $standard = Configs::settings(['runner' => 'pest'])->triage()->learned();

    expect([$learned->percentile(), $learned->least(), $learned->most(), $learned->inAHundred()])->toBe([0.9, 3.0, 8.0, 90.0])
        ->and([$standard->percentile(), $standard->least(), $standard->most(), $standard->inAHundred()])->toBe([0.99, 2.0, 10.0, 99.0])
        ->and(Configs::settings(['runner' => 'pest', 'timeouts' => ['learned' => ['percentile' => 0.9912]]])->triage()->learned()->inAHundred())->toBe(99.12);
});

it('reads how much of its limits a ledger keeps, and how long a read of one may take', function (): void {
    $kept = Configs::settings(['runner' => 'pest', 'proofs' => ['keep' => ['kills' => 50, 'runs' => 3], 'limits' => ['read' => '90s']]])->proofs()->retained();
    $standard = Configs::settings(['runner' => 'pest'])->proofs()->retained();

    expect([$kept->kills(), $kept->runs(), $kept->read()->seconds()])->toBe([50, 3, 90.0])
        ->and(LedgerLimits::kept($kept)->seconds())->toBe(90.0)
        ->and([$standard->kills(), $standard->runs(), $standard->read()->seconds()])->toBe([2000, 10, 60.0]);
});

it('reads what an analyser reads and when a mutator\'s rate decides', function (): void {
    $set = Configs::settings(['runner' => 'pest', 'staticCheck' => ['dependents' => 3, 'preCheckAfter' => 7]])->staticCheck();
    $standard = Configs::settings(['runner' => 'pest'])->staticCheck();

    expect([$set->dependents(), $set->preCheckAfter()])->toBe([3, 7])
        ->and([$standard->dependents(), $standard->preCheckAfter()])->toBe([25, 50]);
});

it('reads how large a suite may name a hot path, the memory doctor wants, and a daemon\'s wait for a request', function (): void {
    $set = Configs::settings(['runner' => 'pest', 'local' => ['memoryHeadroom' => 3, 'serveRequest' => '9s']])->local();
    $standard = Configs::settings(['runner' => 'pest'])->local();

    expect([$set->memoryHeadroom(), $set->serveRequest()->seconds()])->toBe([3, 9.0])
        ->and([$standard->memoryHeadroom(), $standard->serveRequest()->seconds()])->toBe([2, 5.0]);
});

it('refuses a least learned multiple past the most', function (): void {
    $refused = Configs::validated(['runner' => 'pest', 'timeouts' => ['learned' => ['least' => 9, 'most' => 8]]]);
    $held = Configs::validated(['runner' => 'pest', 'timeouts' => ['learned' => ['least' => 8, 'most' => 8]]]);

    expect(Configs::problems($refused))->toBe(['timeouts.learned.least: expected at most timeouts.learned.most, 8, got 9'])
        ->and($held)->toBeInstanceOf(Settings::class);
});
