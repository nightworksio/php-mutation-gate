<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ToldSeconds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('reads the seconds a process was told, a fraction of one too', function (string $told, float $seconds): void {
    expect(ToldSeconds::read($told))->toEqual(Seconds::of($seconds));
})->with([
    'whole seconds' => ['10', 10.0],
    'as the gate writes them' => ['2.500000', 2.5],
    'under a second' => ['0.5', 0.5],
]);

it('reads none where a process was told nothing, no number, or no time past nothing', function (string|false $told): void {
    expect(ToldSeconds::read($told))->toBeInstanceOf(NotGiven::class);
})->with([
    'nothing told' => [false],
    'empty' => [''],
    'a number with a unit' => ['10s'],
    'no time' => ['0'],
    'less than none' => ['-0.5'],
]);
