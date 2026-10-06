<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('keeps seconds between its floor and its most', function (float $seconds, float $kept): void {
    expect(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->kept(Seconds::of($seconds)))->toEqual(Seconds::of($kept));
})->with([
    'under the floor' => [4.0, 10.0],
    'the floor' => [10.0, 10.0],
    'between' => [42.0, 42.0],
    'the most' => [300.0, 300.0],
    'over the most' => [301.0, 300.0],
]);

it('has no floor up to a most, and keeps its floor when a retry raises its most', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->upToInstead(Seconds::of(600.0));

    expect([$bounds->floor(), $bounds->most()])->toEqual([Seconds::of(10.0), Seconds::of(600.0)])
        ->and(LimitBounds::upTo(Seconds::of(30.0))->kept(Seconds::of(0.5)))->toEqual(Seconds::of(0.5))
        ->and(LimitBounds::upTo(Seconds::of(30.0))->floor())->toEqual(Seconds::of(0.0));
});
