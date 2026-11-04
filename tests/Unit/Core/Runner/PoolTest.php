<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('holds how many processes run side by side and how each starts', function (): void {
    $pool = Pool::of(ProcessCount::of(4), Workers::Fork);

    expect([$pool->processes()->count(), $pool->workers()])->toBe([4, Workers::Fork]);
});

it('is one fresh process where nothing asks for more', function (): void {
    expect([Pool::single()->processes()->count(), Pool::single()->workers()])->toBe([1, Workers::Fresh]);
});

it('lays bounds on the start-up it measured, keeping it for the tail, and on none where it measured none', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $measured = Pool::of(ProcessCount::of(4), Workers::Mutants)->startingIn(Seconds::of(2.5));
    $tail = $measured->tail();

    expect($measured->bounding($bounds)->startUp())->toEqual(Seconds::of(2.5))
        ->and([$tail->processes()->count(), $tail->workers()])->toBe([4, Workers::Fresh])
        ->and($tail->bounding($bounds)->startUp())->toEqual(Seconds::of(2.5))
        ->and(Pool::single()->bounding($bounds)->startUp())->toBeInstanceOf(Unmeasured::class);
});

it('runs the tail as runner.workers says: forked under fork, and fresh under mutants and fresh', function (Workers $workers, Workers $tail): void {
    expect(Pool::of(ProcessCount::of(2), $workers)->tail()->workers())->toBe($tail);
})->with([
    'fork' => [Workers::Fork, Workers::Fork],
    'mutants' => [Workers::Mutants, Workers::Fresh],
    'fresh' => [Workers::Fresh, Workers::Fresh],
]);

it('lays a learned multiple on bounds that learn one, keeping it in the tail, and leaves a written one', function (): void {
    $learning = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->multipliedBy(Learned::Learned);
    $pool = Pool::of(ProcessCount::of(4), Workers::Mutants)->learning(Multiple::of(5.5));

    expect($pool->bounding($learning)->multiple())->toEqual(Multiple::of(5.5))
        ->and($pool->tail()->startingIn(Seconds::of(1.0))->bounding($learning)->multiple())->toEqual(Multiple::of(5.5))
        ->and($pool->bounding($learning->multipliedBy(Multiple::of(4.0)))->multiple())->toEqual(Multiple::of(4.0))
        ->and(Pool::single()->bounding($learning)->multiple())->toEqual(Multiple::of(3.0));
});
