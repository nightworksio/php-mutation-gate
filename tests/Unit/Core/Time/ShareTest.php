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
