<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$set = static fn(): Local => Local::of(
    watchBudget: Seconds::of(30),
    prePushBudget: Seconds::of(60),
    serveIdle: Seconds::of(90),
    serveStart: ServeStart::Never,
    serveStartWait: Seconds::of(5),
    inferSubsumed: false,
    watchPoll: Seconds::of(2),
    triageFewest: 3,
    memoryHeadroom: 4,
    serveRequest: Seconds::of(7),
);

it('writes every key it holds as the builder calls it, in the order the builder takes them', function () use ($set): void {
    expect($set()->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith(
        "Local::watchBudget('30s')",
        "Local::prePushBudget('1m')",
        "Local::serveIdle('1m30s')",
        'Local::serveStarting(ServeStart::Never)',
        "Local::serveStartWait('5s')",
        "Local::serveRequest('7s')",
        'Local::runningSubsumed()',
        "Local::watchPoll('2s')",
        'Local::memoryHeadroom(4)',
        'Local::triageFewest(3)',
    ))->and(Local::of(inferSubsumed: true)->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith('Local::inferringSubsumed()'));
});

it('reads back the daemon\'s idle time, how watch starts it and whether a run infers subsumed mutants, each as set or its default', function () use ($set): void {
    $standard = Local::of();

    $local = $set();

    expect([$local->serve()->idle()->written(), $local->serve()->start(), $local->inferSubsumed(), Local::of(inferSubsumed: true)->inferSubsumed()])
        ->toBe(['1m30s', ServeStart::Never, false, true])
        ->and([$standard->serve()->idle()->written(), $standard->serve()->start(), $standard->inferSubsumed()])
        ->toBe(['30m', ServeStart::Watch, true]);
});
