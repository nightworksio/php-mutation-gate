<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\OutbreakCalls;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('shares outbreak.so\'s step limits among a stream\'s children, unlimited from a fork, then held to the parent\'s steps past the floor', function (): void {
    expect(OutbreakCalls::asked(<<<'CODE'
        $steps = new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps($plan);
        $steps->opened(1);
        $steps->bound(1);
        $steps->held(1, 10, 30);
        CODE, sprintf('%s/results.jsonl', Scratch::directory())))
        ->toBe('share 2;limit 1 unlimited;bind 1;limit 1 130');
});

it('gives a child the first slot no running child holds, the first where all are held, and holds a slot past its steps', function (): void {
    expect(FreshProcess::answer(<<<'CODE'
        function outbreak_steps_share(int $children): bool { return true; }
        function outbreak_steps(): ?int { return 7; }
        $plan = new NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan('/r', [], [], [], NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces::fixed(3), new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit(1.0, 1.0, 10.0, stepFloor: 100));
        $steps = new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps($plan);
        echo json_encode([
            $steps->freeSlot([]), $steps->freeSlot([0]), $steps->freeSlot([0, 1]), $steps->freeSlot([1, 2]),
            $steps->freeSlot([0, 1, 2]), $steps->taken(), $steps->exceeded(SIGXCPU), $steps->exceeded(SIGKILL),
        ]);
        CODE))->toBe('[0,1,2,0,0,7,true,false]');
});

it('gives every child the first slot and counts no steps where the stream counts none, though outbreak.so could', function (): void {
    expect(FreshProcess::answer(<<<'CODE'
        $GLOBALS['shared'] = 'never';
        function outbreak_steps_share(int $children): bool { $GLOBALS['shared'] = 'asked'; return true; }
        function outbreak_steps(): ?int { return 7; }
        $plan = new NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan('/r', [], [], [], NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces::fixed(3), new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit(1.0, 1.0, 10.0));
        $steps = new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps($plan);
        echo json_encode([$steps->freeSlot([0]), $steps->freeSlot([0, 1, 2]), $steps->taken(), $steps->exceeded(SIGXCPU), $GLOBALS['shared']]);
        CODE))->toBe('[0,0,0,false,"never"]');
});

it('counts no steps taken where outbreak.so cannot tell them', function (): void {
    expect(FreshProcess::answer(<<<'CODE'
        function outbreak_steps_share(int $children): bool { return true; }
        function outbreak_steps(): ?int { return null; }
        $plan = new NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan('/r', [], [], [], NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces::fixed(3), new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit(1.0, 1.0, 10.0, stepFloor: 100));
        echo (new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps($plan))->taken();
        CODE))->toBe('0');
});

it('lets a child of a running test out of its place past the limit the map\'s time sets, past the floor where it has none, and never where it counts steps', function (): void {
    expect(FreshProcess::answer(<<<'CODE'
        function outbreak_steps_share(int $children): bool { return true; }
        function outbreak_steps(): ?int { return 7; }
        $limit = static fn(int $floor): NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit => new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit(3.0, 2.0, 30.0, $floor);
        $places = NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces::fixed(2);
        $timed = new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps(new NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan('/r', [], [], [], $places, $limit(0)));
        $counted = new NightWorksIO\MutationGate\Adapter\Pest\Split\ChildSteps(new NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan('/r', [], [], [], $places, $limit(100)));
        echo json_encode([$timed->releasedAfter(1.5), $timed->releasedAfter(false), $counted->releasedAfter(1.5), $timed->secondsOf(1.5), $counted->secondsOf(1.5)]);
        CODE))->toBe('[4.5,2,30,4.5,30]');
});
