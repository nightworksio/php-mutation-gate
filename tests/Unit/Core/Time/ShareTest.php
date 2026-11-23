<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Share;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('takes its share of a time, the whole of it where it is whole, and leaves a time with no limit without one', function (): void {
    expect(Share::of(0.25)->taken(Seconds::of(40.0)))->toEqual(Seconds::of(10.0))
        ->and(Share::whole()->taken(Seconds::of(40.0)))->toEqual(Seconds::of(40.0))
        ->and(Share::whole())->toEqual(Share::of(1.0))
        ->and(Share::of(0.25)->taken(Unlimited::time()))->toEqual(Unlimited::time());
});

it('leaves of a time what is not kept back as its share of another, never less than none, and all of it as the whole', function (): void {
    expect(Share::of(0.1)->leaving(Seconds::of(200.0), Seconds::of(300.0)))->toEqual(Seconds::of(170.0))
        ->and(Share::of(0.5)->leaving(Seconds::of(100.0), Seconds::of(200.0)))->toEqual(Seconds::of(0.0))
        ->and(Share::of(0.5)->leaving(Seconds::of(50.0), Seconds::of(200.0)))->toEqual(Seconds::of(0.0))
        ->and(Share::whole()->leaving(Seconds::of(200.0), Seconds::of(300.0)))->toEqual(Seconds::of(200.0))
        ->and(Share::of(0.5)->leaving(new Unlimited(), Seconds::of(300.0)))->toEqual(new Unlimited())
        ->and(Share::of(0.5)->leaving(Seconds::of(200.0), new Unlimited()))->toEqual(Seconds::of(200.0));
});
