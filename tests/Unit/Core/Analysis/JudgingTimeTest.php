<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\JudgingTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('expects judging to take the covering tests\' own time, or that time at the pace learned', function (JudgingTime $judging, float $expected): void {
    expect($judging->of(Seconds::of(8.0)))->toEqual(Seconds::of($expected));
})->with([
    'covering' => [fn(): JudgingTime => JudgingTime::covering(), 8.0],
    'no pace learned' => [fn(): JudgingTime => JudgingTime::atPace(NotGiven::value()), 8.0],
    'a quarter' => [fn(): JudgingTime => JudgingTime::atPace(Multiple::of(0.25)), 2.0],
    'one and a half' => [fn(): JudgingTime => JudgingTime::atPace(Multiple::of(1.5)), 12.0],
]);
