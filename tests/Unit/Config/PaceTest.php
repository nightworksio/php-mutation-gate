<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Pace;

it('writes the pace it sets under the runner, for a layer that chooses no runner', function (): void {
    expect(Pace::of(recycleAfter: 8, warmSlack: '30s', poll: 0.25)->written()->line())
        ->toBe('{"runner":{"recycleAfter":8,"warmSlack":"30s","poll":0.25}}');
});
